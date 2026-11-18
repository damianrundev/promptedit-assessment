<?php

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = userWithCredits(10);
    $this->g = newGeneration($this->user, ['prompt' => 'a red fox', 'reference_images' => ['https://example.com/a.png']]);
});

/** Runs the job once with a mock queue job so release() can be observed. */
function runSubmit(Generation $g): Job
{
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job = new SubmitGeneration($g->id);
    $job->setJob($queueJob);
    $job->handle(app(PixelforgeClient::class), app(GenerationStateApplier::class));

    return $queueJob;
}

function error(string $code, int $status, array $headers = []): Illuminate\Http\Client\Response|GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "msg $code"]], $status, $headers);
}

it('creates the generation with the local uuid as Idempotency-Key and metadata.local_id', function () {
    Http::fake([PF.'/generations' => Http::response(remoteGeneration($this->g, 'queued'), 202)]);

    runSubmit($this->g);

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && $r->header('Idempotency-Key') === [$this->g->id]
        && $r['metadata'] === ['local_id' => $this->g->id]
        && $r['reference_images'] === ['https://example.com/a.png']
        && $r['quality'] === 'medium');
    expect($this->g->fresh())->status->toBe('queued')->pixelforge_id->not->toBeNull();
});

it('retries 429 rate_limit_exceeded honouring Retry-After, without touching money', function () {
    Http::fake([PF.'/generations' => error('rate_limit_exceeded', 429, ['Retry-After' => '17'])]);

    $job = runSubmit($this->g);

    $job->shouldHaveReceived('release')->withArgs(fn ($delay) => $delay >= 17 && $delay <= 120);
    expect($this->g->fresh())->status->toBe('pending')->submit_retries->toBe(1)
        ->and(LedgerEntry::where('generation_id', $this->g->id)->count())->toBe(1); // only the reservation
});

it('retries 503 overloaded with growing backoff', function () {
    Http::fake([PF.'/generations' => error('overloaded', 503, ['Retry-After' => '2'])]);

    $first = runSubmit($this->g);
    $second = runSubmit($this->g);

    $delays = [];
    foreach ([$first, $second] as $j) {
        $j->shouldHaveReceived('release')->withArgs(function ($d) use (&$delays) { $delays[] = $d; return true; });
    }
    expect($delays[1])->toBeGreaterThan($delays[0]);
});

it('uses release() and keeps waiting on concurrency_limit_exceeded', function () {
    Http::fake([PF.'/generations' => error('concurrency_limit_exceeded', 429, ['Retry-After' => '5'])]);

    $job = runSubmit($this->g);

    $job->shouldHaveReceived('release')->with(5);
    expect($this->g->fresh())->status->toBe('pending')->note->toContain('free slot');
});

it('gives up after the retry cap: rejected, free, reservation released', function () {
    Http::fake([PF.'/generations' => error('overloaded', 503)]);
    $this->g->update(['submit_retries' => SubmitGeneration::MAX_THROTTLE_RETRIES]);

    runSubmit($this->g);

    expect($this->g->fresh())->status->toBe('rejected')->error_code->toBe('overloaded')
        ->and($this->user->balanceNano())->toBe(10 * Money::NANO);
});

it('decides on the error code, not the HTTP status', function (string $code, int $status) {
    Http::fake([PF.'/generations' => error($code, $status)]);

    $job = runSubmit($this->g);

    $job->shouldNotHaveReceived('release');
    expect($this->g->fresh())->status->toBe('rejected')->error_code->toBe($code)->charged_nano->toBe(0)
        ->and($this->user->balanceNano())->toBe(10 * Money::NANO)
        ->and(LedgerEntry::where('generation_id', $this->g->id)->pluck('type')->sort()->values()->all())->toBe(['release', 'reserve']);
})->with([
    'blocked content' => ['content_policy_violation', 400],
    'invalid params' => ['validation_failed', 422],
    'daily quota (a 429 that must NOT be retried)' => ['daily_limit_exceeded', 429],
]);

it('does not create or charge anything new when the create response is an idempotent replay', function () {
    // First attempt reached Pixelforge but the response was lost; meanwhile the webhook finished it.
    $applier = app(GenerationStateApplier::class);
    $applier->apply(remoteGeneration($this->g, 'succeeded'));
    $charged = $this->g->fresh()->charged_nano;

    // The retried create is replayed by Pixelforge with the generation "as it is now".
    Http::fake([PF.'/generations' => Http::response(remoteGeneration($this->g->fresh(), 'succeeded'), 202, ['Idempotent-Replayed' => 'true'])]);
    $job = new SubmitGeneration($this->g->id);
    $job->handle(app(PixelforgeClient::class), $applier); // status is no longer pending: nothing is sent
    Http::assertNothingSent();

    // And even if the replay is applied directly, money does not move again.
    $applier->apply(remoteGeneration($this->g->fresh(), 'succeeded'));

    expect(LedgerEntry::where('generation_id', $this->g->id)->where('type', 'charge')->count())->toBe(1)
        ->and(Generation::count())->toBe(1)
        ->and($this->g->fresh()->charged_nano)->toBe($charged);
});

it('applies a replayed create response that is still queued without charging', function () {
    Http::fake([PF.'/generations' => Http::response(remoteGeneration($this->g, 'processing'), 202, ['Idempotent-Replayed' => 'true'])]);

    runSubmit($this->g);

    expect($this->g->fresh())->status->toBe('processing')
        ->and(LedgerEntry::where('type', 'charge')->count())->toBe(0);
});

it('retries a network failure safely (idempotency key makes it a no-op if it did get through)', function () {
    Http::fake([PF.'/generations' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);

    $job = runSubmit($this->g);

    $job->shouldHaveReceived('release');
    expect($this->g->fresh())->status->toBe('pending');
});

<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = userWithCredits(10);
    $this->g = newGeneration($this->user);
    Queue::fake();
});

function chargeEntries(Generation $g)
{
    return LedgerEntry::where('generation_id', $g->id)->where('type', 'charge');
}

it('charges once when the same succeeded event is delivered twice', function () {
    $event = webhookEvent('evt_dup', 'generation.succeeded', remoteGeneration($this->g, 'succeeded'));

    postWebhook($event)->assertOk();
    postWebhook($event)->assertOk();

    $g = $this->g->fresh();
    expect($g->status)->toBe('succeeded')
        ->and($g->provider_cost_nano)->toBe(DEFAULT_PROVIDER_COST)
        ->and($g->charged_nano)->toBe(Money::withMarkup(DEFAULT_PROVIDER_COST))
        ->and(chargeEntries($g)->count())->toBe(1)
        ->and(\App\Models\WebhookEvent::where('event_id', 'evt_dup')->count())->toBe(1)
        ->and($this->user->balanceNano())->toBe(10 * Money::NANO - Money::withMarkup(DEFAULT_PROVIDER_COST));
});

it('charges once even when a different event id carries the same final state', function () {
    postWebhook(webhookEvent('evt_a', 'generation.succeeded', remoteGeneration($this->g, 'succeeded')))->assertOk();
    postWebhook(webhookEvent('evt_b', 'generation.succeeded', remoteGeneration($this->g, 'succeeded')))->assertOk();

    expect(chargeEntries($this->g)->count())->toBe(1)
        ->and($this->user->balanceNano())->toBe(10 * Money::NANO - Money::withMarkup(DEFAULT_PROVIDER_COST));
});

it('ends up succeeded when succeeded arrives before processing', function () {
    postWebhook(webhookEvent('evt_s', 'generation.succeeded', remoteGeneration($this->g, 'succeeded')))->assertOk();
    postWebhook(webhookEvent('evt_p', 'generation.processing', remoteGeneration($this->g, 'processing')))->assertOk();
    postWebhook(webhookEvent('evt_q', 'generation.processing', remoteGeneration($this->g, 'queued')))->assertOk();

    expect($this->g->fresh()->status)->toBe('succeeded')
        ->and(chargeEntries($this->g)->count())->toBe(1);
});

it('never moves a failed generation back to processing or forward to succeeded', function () {
    postWebhook(webhookEvent('evt_f', 'generation.failed', remoteGeneration($this->g, 'failed')))->assertOk();
    postWebhook(webhookEvent('evt_p', 'generation.processing', remoteGeneration($this->g, 'processing')))->assertOk();
    postWebhook(webhookEvent('evt_s', 'generation.succeeded', remoteGeneration($this->g, 'succeeded')))->assertOk();

    $g = $this->g->fresh();
    expect($g->status)->toBe('failed')
        ->and($g->error_code)->toBe('internal_error')
        ->and($g->charged_nano)->toBe(0)
        ->and(chargeEntries($g)->count())->toBe(0)
        ->and($this->user->balanceNano())->toBe(10 * Money::NANO); // everything released
});

it('links a webhook that arrives before the create response via metadata.local_id', function () {
    expect($this->g->pixelforge_id)->toBeNull();

    postWebhook(webhookEvent('evt_early', 'generation.processing', remoteGeneration($this->g, 'processing')))->assertOk();

    $g = $this->g->fresh();
    expect($g->pixelforge_id)->toStartWith('gen_TEST')->and($g->status)->toBe('processing');

    // …and the late create response (queued) must not drag it back.
    app(\App\Services\GenerationStateApplier::class)->apply(remoteGeneration($g, 'queued'));
    expect($g->fresh()->status)->toBe('processing');
});

it('ignores events for generations it does not know, but still records them', function () {
    $stranger = new Generation(['id' => '9d3f8a52-1111-4222-8333-444455556666', 'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []]);
    $stranger->pixelforge_id = 'gen_OTHER';

    postWebhook(webhookEvent('evt_x', 'generation.succeeded', remoteGeneration($stranger, 'succeeded')))->assertOk();

    expect(\App\Models\WebhookEvent::where('event_id', 'evt_x')->exists())->toBeTrue()
        ->and(LedgerEntry::where('type', 'charge')->count())->toBe(0);
});

it('releases the reservation exactly when charging less than the maximum', function () {
    postWebhook(webhookEvent('evt_s', 'generation.succeeded', remoteGeneration($this->g, 'succeeded')))->assertOk();

    $types = LedgerEntry::where('generation_id', $this->g->id)->orderBy('id')->pluck('amount_nano', 'type');
    expect($types['reserve'])->toBe(-$this->g->reserved_nano)
        ->and($types['release'])->toBe($this->g->reserved_nano)
        ->and($types['charge'])->toBe(-Money::withMarkup(DEFAULT_PROVIDER_COST));
});

it('prices with the rates carried by the generation, not the current ones', function () {
    $rates = ['output_tokens' => '80.00'] + pfPricing()['rates']; // output doubled when this generation was created
    postWebhook(webhookEvent('evt_s', 'generation.succeeded', remoteGeneration($this->g, 'succeeded', ['rates' => $rates])))->assertOk();

    expect($this->g->fresh()->provider_cost_nano)->toBe(4 * 5000 + 1100 * 80000 + 1000 * 12500);
});

it('queues the image download when a generation succeeds', function () {
    postWebhook(webhookEvent('evt_s', 'generation.succeeded', remoteGeneration($this->g, 'succeeded')))->assertOk();

    Queue::assertPushed(\App\Jobs\DownloadGenerationImage::class, 1);
});

it('responds with a failure (so Pixelforge retries) and rolls the event back if applying throws', function () {
    $bad = remoteGeneration($this->g, 'succeeded', ['usage' => null]); // malformed: no usage

    $this->withoutExceptionHandling();
    expect(fn () => postWebhook(webhookEvent('evt_bad', 'generation.succeeded', $bad)))->toThrow(\TypeError::class);
    expect(\App\Models\WebhookEvent::where('event_id', 'evt_bad')->exists())->toBeFalse();
});

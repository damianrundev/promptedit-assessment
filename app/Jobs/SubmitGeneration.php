<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\ApiResponse;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the generation to Pixelforge. Safe to run any number of times: the local UUID is the
 * Idempotency-Key, so a retry after a timeout or a replayed response never creates (or bills) a second one.
 */
class SubmitGeneration implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** Retries for rate limits, overloads and network failures before giving up. */
    public const MAX_THROTTLE_RETRIES = 6;

    /** How long we keep waiting for one of the 3 concurrency slots. */
    public const MAX_CONCURRENCY_WAIT_SECONDS = 600;

    /** We do our own accounting (above); the job itself can be released many times. */
    public int $tries = 0;

    public function __construct(public string $generationId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): void
    {
        $generation = Generation::find($this->generationId);
        if (! $generation || $generation->status !== Generation::PENDING) {
            return; // already accepted (webhook got there first) or finished
        }

        $response = $client->createGeneration($this->payload($generation), $generation->id);

        if ($response->ok()) {           // 202, including Idempotent-Replayed: true
            $applier->apply($response->body);

            return;
        }

        // Decide on the error `code`, never on the HTTP status.
        match ($response->code()) {
            'concurrency_limit_exceeded' => $this->waitForSlot($generation, $response, $applier),
            'rate_limit_exceeded', 'overloaded' => $this->retryOrGiveUp($generation, $response, $applier),
            'content_policy_violation', 'validation_failed', 'daily_limit_exceeded',
            'invalid_api_key', 'idempotency_key_reused' => $applier->reject($generation->id, $response->code(), $response->message()),
            // No code at all: network failure / non-JSON 5xx. Safe to retry thanks to the idempotency key.
            default => $response->status === 0 || $response->status >= 500 || $response->code() === null
                ? $this->retryOrGiveUp($generation, $response, $applier)
                : $applier->reject($generation->id, $response->code(), $response->message()),
        };
    }

    private function retryOrGiveUp(Generation $g, ApiResponse $r, GenerationStateApplier $applier): void
    {
        if ($g->submit_retries >= self::MAX_THROTTLE_RETRIES) {
            $applier->reject($g->id, $r->code() ?? 'provider_unavailable', $r->message());

            return;
        }

        $g->increment('submit_retries');
        $g->update(['note' => 'Pixelforge is busy, retrying… (attempt '.($g->submit_retries + 1).')']);

        // Honour Retry-After, plus exponential backoff so repeated failures spread out.
        $this->release(min(120, max($r->retryAfter ?? 2, 1) + 2 ** $g->submit_retries));
    }

    private function waitForSlot(Generation $g, ApiResponse $r, GenerationStateApplier $applier): void
    {
        if ($g->created_at->diffInSeconds(now()) > self::MAX_CONCURRENCY_WAIT_SECONDS) {
            $applier->reject($g->id, 'concurrency_limit_exceeded', $r->message());

            return;
        }

        $g->update(['note' => 'Waiting for a free slot on Pixelforge (max 3 at once)…']);
        $this->release(max($r->retryAfter ?? 5, 1));
    }

    private function payload(Generation $g): array
    {
        $payload = [
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'metadata' => ['local_id' => $g->id],   // lets a webhook that beats our response find this row
        ];
        if ($g->reference_images) {
            $payload['reference_images'] = $g->reference_images;
        }

        return $payload;
    }
}

<?php

namespace App\Services;

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Services\Pixelforge\PriceEstimator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single place where a Pixelforge generation object changes local state and money.
 * Webhooks, the create response and the polling fallback all call apply(), so the outcome
 * is the same (and idempotent) whichever of them delivers a given state first.
 *
 * Rules: succeeded/failed/rejected are final; processing never overrides a final state;
 * money moves exactly once (see Ledger's unique index as a last line of defence).
 */
class GenerationStateApplier
{
    public function __construct(private Ledger $ledger, private PriceEstimator $estimator) {}

    /** @param  array  $remote  Pixelforge generation object. Returns null when it isn't one of ours. */
    public function apply(array $remote): ?Generation
    {
        return DB::transaction(function () use ($remote) {
            $g = $this->locate($remote);
            if (! $g) {
                return null;
            }

            $g->pixelforge_id ??= $remote['id'];
            $g->rates ??= $remote['rates'] ?? null;

            if (! $g->isTerminal()) {
                match ($remote['status'] ?? null) {
                    'queued' => $this->moveForward($g, Generation::QUEUED, [Generation::PENDING]),
                    'processing' => $this->processing($g, $remote),
                    'succeeded' => $this->succeed($g, $remote),
                    'failed' => $this->fail($g, $remote),
                    default => null,
                };
            }

            $g->save();

            return $g;
        });
    }

    /** Definitive refusal (nothing was created at Pixelforge): free, reservation released. */
    public function reject(string $generationId, string $code, ?string $message): void
    {
        DB::transaction(function () use ($generationId, $code, $message) {
            $g = Generation::whereKey($generationId)->lockForUpdate()->first();
            if (! $g || $g->isTerminal()) {
                return;
            }
            $this->ledger->release($g);
            $g->update([
                'status' => Generation::REJECTED,
                'error_code' => $code,
                'error_message' => $message,
                'note' => null,
                'charged_nano' => 0,
                'completed_at' => now(),
            ]);
        });
    }

    private function locate(array $remote): ?Generation
    {
        $g = isset($remote['id']) ? Generation::where('pixelforge_id', $remote['id'])->lockForUpdate()->first() : null;

        // The webhook can beat the create response: fall back to the id we put in metadata.
        $localId = $remote['metadata']['local_id'] ?? null;
        if (! $g && is_string($localId) && Str::isUuid($localId)) {
            $g = Generation::whereKey($localId)->lockForUpdate()->first();
        }

        return $g;
    }

    private function moveForward(Generation $g, string $to, array $from): void
    {
        if (in_array($g->status, $from, true)) {
            $g->status = $to;
            $g->note = null;
        }
    }

    private function processing(Generation $g, array $remote): void
    {
        $this->moveForward($g, Generation::PROCESSING, [Generation::PENDING, Generation::QUEUED]);
        $g->started_at ??= $this->time($remote['started_at'] ?? null);
    }

    private function succeed(Generation $g, array $remote): void
    {
        $providerCost = $this->estimator->cost($remote['usage'], $remote['rates'] ?? $g->rates);
        $userCost = Money::withMarkup($providerCost);

        if (! $this->ledger->settle($g, $userCost)) {
            return; // already charged: never charge twice
        }

        $g->fill([
            'status' => Generation::SUCCEEDED,
            'usage' => $remote['usage'],
            'rates' => $remote['rates'] ?? $g->rates,
            'provider_cost_nano' => $providerCost,
            'charged_nano' => $userCost,
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
            'started_at' => $g->started_at ?? $this->time($remote['started_at'] ?? null),
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
            'error_code' => null,
            'error_message' => null,
            'note' => null,
        ]);

        DownloadGenerationImage::dispatch($g->id)->afterCommit();
    }

    private function fail(Generation $g, array $remote): void
    {
        $this->ledger->release($g);
        $g->fill([
            'status' => Generation::FAILED,
            'error_code' => $remote['error']['code'] ?? 'generation_failed',
            'error_message' => $remote['error']['message'] ?? null,
            'charged_nano' => 0,
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
            'note' => null,
        ]);
    }

    private function time(?string $iso): ?CarbonImmutable
    {
        return $iso ? CarbonImmutable::parse($iso)->utc() : null;
    }
}

<?php

namespace App\Services;

use App\Models\Generation;
use App\Services\Pixelforge\ApiResponse;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Per-day, per-user financials and the reconciliation against GET /v1/usage.
 * Pixelforge attributes a generation to the UTC day it COMPLETED, so we group on completed_at, not created_at.
 */
class AdminReport
{
    private const KINDS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @return array{days: array, totals: array, daily: array<string, array>}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // ponytail: aggregates in PHP; fine for thousands of rows, move to SQL GROUP BY if it grows.
        $rows = Generation::with('user:id,name,email')
            ->where('status', Generation::SUCCEEDED)
            ->where('completed_at', '>=', $from->startOfDay())
            ->where('completed_at', '<', $to->addDay()->startOfDay())
            ->get();

        $daily = [];
        foreach ($rows as $g) {
            $day = &$daily[$g->completed_at->utc()->toDateString()];
            $day ??= ['generations' => 0, 'charged' => 0, 'cost' => 0, 'tokens' => array_fill_keys(self::KINDS, 0), 'users' => []];
            $day['users'][$g->user_id] ??= ['name' => $g->user->name, 'email' => $g->user->email, 'generations' => 0, 'charged' => 0, 'cost' => 0];

            $this->add($day, $g);
            $this->add($day['users'][$g->user_id], $g);
            foreach (self::KINDS as $k) {
                $day['tokens'][$k] += (int) ($g->usage[$k] ?? 0);
            }
            unset($day);
        }

        $days = [];
        for ($d = $to; $d >= $from; $d = $d->subDay()) {
            $key = $d->toDateString();
            $day = $daily[$key] ?? ['generations' => 0, 'charged' => 0, 'cost' => 0, 'users' => []];
            $days[] = [
                'date' => $key,
                ...$this->money($day),
                'users' => collect($day['users'])->map(fn ($u) => ['name' => $u['name'], 'email' => $u['email'], 'generations' => $u['generations'], ...$this->money($u)])->values()->all(),
            ];
        }

        $sum = fn (string $k) => array_sum(array_column($daily, $k));

        return [
            'days' => $days,
            'totals' => $this->money(['generations' => $sum('generations'), 'charged' => $sum('charged'), 'cost' => $sum('cost')]),
            'daily' => $daily,
        ];
    }

    /**
     * Compare our recorded provider costs with Pixelforge's own report, day by day.
     *
     * @return array<int, array{date:string, ok:bool, ours:string, theirs:string, our_generations:int, their_generations:int}>
     */
    public function reconcile(array $daily, ApiResponse $usage): array
    {
        $out = [];
        foreach (array_reverse($usage->body['data'] ?? []) as $theirs) {
            $ours = $daily[$theirs['date']] ?? ['generations' => 0, 'cost' => 0, 'tokens' => array_fill_keys(self::KINDS, 0)];
            $tokensMatch = collect(self::KINDS)->every(fn ($k) => (int) $theirs[$k] === ($ours['tokens'][$k] ?? 0));

            $out[] = [
                'date' => $theirs['date'],
                'ok' => $ours['cost'] === Money::fromDecimal($theirs['cost_usd']) && $ours['generations'] === (int) $theirs['generations'] && $tokensMatch,
                'ours' => Money::toDecimal($ours['cost']),
                'theirs' => $theirs['cost_usd'],
                'our_generations' => $ours['generations'],
                'their_generations' => (int) $theirs['generations'],
            ];
        }

        return $out;
    }

    private function money(array $b): array
    {
        return [
            'generations' => $b['generations'],
            'charged' => Money::toDecimal($b['charged']),
            'cost' => Money::toDecimal($b['cost']),
            'profit' => Money::toDecimal($b['charged'] - $b['cost']),
        ];
    }

    private function add(array &$bucket, Generation $g): void
    {
        $bucket['generations']++;
        $bucket['charged'] += $g->charged_nano;
        $bucket['cost'] += $g->provider_cost_nano;
    }
}

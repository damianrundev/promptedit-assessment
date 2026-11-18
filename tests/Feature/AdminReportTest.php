<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\AdminReport;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\ApiResponse;
use App\Support\Money;
use Carbon\CarbonImmutable;

function finishedAt(Generation $g, string $completedAt): void
{
    app(GenerationStateApplier::class)->apply(remoteGeneration($g, 'succeeded', ['completed_at' => $completedAt]));
}

function usageResponse(array $days): ApiResponse
{
    return new ApiResponse(200, ['data' => array_map(fn ($d) => $d + [
        'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0,
    ], $days)]);
}

it('attributes each cost to the UTC day it completed, not the day it was created', function () {
    $g = newGeneration(userWithCredits(10));
    $g->forceFill(['created_at' => '2026-11-01 23:59:00'])->save();
    finishedAt($g, '2026-11-02T00:00:30Z'); // completes a minute into the next UTC day

    $r = app(AdminReport::class)->build(CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-11-02', 'UTC'));
    $days = collect($r['days'])->keyBy('date');

    expect($days['2026-11-01']['generations'])->toBe(0)
        ->and($days['2026-11-01']['cost'])->toBe('0.000000000')
        ->and($days['2026-11-02']['generations'])->toBe(1)
        ->and($days['2026-11-02']['cost'])->toBe(Money::toDecimal(DEFAULT_PROVIDER_COST))
        ->and($days['2026-11-02']['charged'])->toBe(Money::toDecimal(Money::withMarkup(DEFAULT_PROVIDER_COST)))
        ->and($days['2026-11-02']['profit'])->toBe(Money::toDecimal(Money::withMarkup(DEFAULT_PROVIDER_COST) - DEFAULT_PROVIDER_COST))
        ->and($days['2026-11-02']['users'][0]['generations'])->toBe(1);
});

it('splits a day per user and ignores failed generations', function () {
    $a = userWithCredits(10, ['name' => 'Ann']);
    $b = userWithCredits(10, ['name' => 'Bob']);
    foreach ([$a, $a, $b] as $u) {
        finishedAt(newGeneration($u), '2026-11-03T10:00:00Z');
    }
    app(GenerationStateApplier::class)->apply(remoteGeneration(newGeneration($b), 'failed', ['completed_at' => '2026-11-03T11:00:00Z']));

    $day = collect(app(AdminReport::class)->build(CarbonImmutable::parse('2026-11-03', 'UTC'), CarbonImmutable::parse('2026-11-03', 'UTC'))['days'])->first();

    expect($day['generations'])->toBe(3)
        ->and(collect($day['users'])->pluck('generations', 'name')->all())->toBe(['Ann' => 2, 'Bob' => 1]);
});

it('marks each day green or red against /v1/usage', function () {
    finishedAt(newGeneration(userWithCredits(10)), '2026-11-03T10:00:00Z');
    $report = app(AdminReport::class);
    $daily = $report->build(CarbonImmutable::parse('2026-11-02', 'UTC'), CarbonImmutable::parse('2026-11-04', 'UTC'))['daily'];

    $usage = usageResponse([
        ['date' => '2026-11-02', 'generations' => 0, 'cost_usd' => '0.000000000'],
        ['date' => '2026-11-03', 'generations' => 1, 'cost_usd' => Money::toDecimal(DEFAULT_PROVIDER_COST), 'input_text_tokens' => 4, 'output_tokens' => 1100, 'reasoning_tokens' => 1000],
        ['date' => '2026-11-04', 'generations' => 0, 'cost_usd' => '0.000000001'], // off by one nano-dollar
    ]);

    $rows = collect($report->reconcile($daily, $usage))->keyBy('date');

    expect($rows['2026-11-02']['ok'])->toBeTrue()
        ->and($rows['2026-11-03']['ok'])->toBeTrue()
        ->and($rows['2026-11-04']['ok'])->toBeFalse();
});

it('is admin-only and reconciles live against /v1/usage', function () {
    Illuminate\Support\Facades\Http::fake([PF.'/usage*' => Illuminate\Support\Facades\Http::response(['data' => [
        ['date' => now('UTC')->toDateString(), 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
    ]])]);

    $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/report')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Admin/Report')->where('reconciliation.0.ok', true));
});

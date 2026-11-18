<?php

use App\Exceptions\InsufficientCreditsException;
use App\Models\LedgerEntry;
use App\Services\GenerationService;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;

it('reserves the maximum estimated price when a generation is created', function () {
    $user = userWithCredits(10);
    $g = newGeneration($user, ['quality' => 'high', 'size' => '1536x1024', 'prompt' => str_repeat('a', 400), 'reference_images' => ['https://x.test/a.png', 'https://x.test/b.png']]);

    expect($g->reserved_nano)->toBe(467_343_750) // $0.373875 * 1.25
        ->and($user->balanceNano())->toBe(10 * Money::NANO - 467_343_750);
});

it('blocks generating when the balance does not cover the maximum', function () {
    $user = userWithCredits(0.01);
    fakePricing();

    expect(fn () => app(GenerationService::class)->create($user, ['prompt' => 'x', 'size' => '1536x1024', 'quality' => 'high']))
        ->toThrow(InsufficientCreditsException::class);

    expect(App\Models\Generation::count())->toBe(0)
        ->and($user->balanceNano())->toBe(10_000_000);
});

it('does not let two submissions spend the same balance', function () {
    // balance fits exactly one medium 1024x1024 maximum
    fakePricing();
    $max = app(GenerationService::class)->estimate('a red fox', '1024x1024', 'medium', 0)['user_max'];
    $user = userWithCredits(0);
    app(App\Services\Ledger::class)->purchase($user, $max);
    Queue::fake();

    app(GenerationService::class)->create($user, ['prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium']);

    expect(fn () => app(GenerationService::class)->create($user, ['prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium']))
        ->toThrow(InsufficientCreditsException::class)
        ->and($user->balanceNano())->toBe(0);
});

it('charges the real price and releases the rest on success', function () {
    $user = userWithCredits(10);
    $g = newGeneration($user);

    app(App\Services\GenerationStateApplier::class)->apply(remoteGeneration($g, 'succeeded'));

    expect($user->balanceNano())->toBe(10 * Money::NANO - Money::withMarkup(DEFAULT_PROVIDER_COST))
        ->and($g->fresh()->reserved_nano)->toBeGreaterThan($g->fresh()->charged_nano);
});

it('releases everything when the generation fails', function () {
    $user = userWithCredits(10);
    $g = newGeneration($user);
    expect($user->balanceNano())->toBeLessThan(10 * Money::NANO);

    app(App\Services\GenerationStateApplier::class)->apply(remoteGeneration($g, 'failed'));

    expect($user->balanceNano())->toBe(10 * Money::NANO)
        ->and($g->fresh()->charged_nano)->toBe(0);
});

it('makes a double charge impossible at the database level', function () {
    $user = userWithCredits(10);
    $g = newGeneration($user);
    $ledger = app(App\Services\Ledger::class);

    expect($ledger->settle($g, 1000))->toBeTrue()
        ->and($ledger->settle($g, 1000))->toBeFalse()
        ->and($ledger->release($g))->toBeFalse()
        ->and(LedgerEntry::where('generation_id', $g->id)->where('type', 'charge')->count())->toBe(1);

    expect(fn () => LedgerEntry::create(['user_id' => $user->id, 'generation_id' => $g->id, 'type' => 'charge', 'amount_nano' => -1]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('adds credits through the simulated purchase', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['amount' => 10])->assertRedirect();
    expect($user->balanceNano())->toBe(10 * Money::NANO);

    $this->actingAs($user)->post('/credits', ['amount' => 7])->assertSessionHasErrors('amount');
});

it('shows live estimates and refuses to submit without enough credit', function () {
    fakePricing();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/generate/estimate', ['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['a', 'b']])
        ->assertOk()->assertJson(['max' => '$0.467344', 'can_afford' => false]);

    Queue::fake();
    $this->actingAs($user)->post('/generate', ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'])->assertSessionHasErrors('credits');
});

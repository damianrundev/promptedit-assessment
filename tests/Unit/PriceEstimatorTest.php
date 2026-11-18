<?php

use App\Services\Pixelforge\PriceEstimator;
use App\Support\Money;

function pricing(): array
{
    return [
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
        ],
    ];
}

it('matches the documented worst case of $0.373875 exactly', function () {
    $e = (new PriceEstimator)->estimate(pricing(), str_repeat('a', 400), '1536x1024', 'high', 2);

    expect($e['provider_max'])->toBe(373_875_000)
        ->and(Money::toDecimal($e['provider_max']))->toBe('0.373875000')
        ->and($e['tokens']['max'])->toEqual(['input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000]);
});

it('applies the 25% markup on top of Pixelforge cost', function () {
    $e = (new PriceEstimator)->estimate(pricing(), str_repeat('a', 400), '1536x1024', 'high', 2);

    expect($e['user_max'])->toBe(467_343_750) // 373_875_000 * 1.25
        ->and($e['user_min'])->toBe(Money::withMarkup($e['provider_min']))
        ->and($e['provider_min'])->toBeLessThan($e['provider_max']);
});

it('counts characters, not bytes, and never prices below zero', function () {
    $e = (new PriceEstimator)->estimate(pricing(), 'ñññ', '1024x1024', 'low', 0);

    expect($e['tokens']['max']['input_text_tokens'])->toBe(2) // ceil(3/4)=1, +ceil(0.15)=1
        ->and($e['tokens']['min']['input_text_tokens'])->toBe(0)
        ->and($e['provider_min'])->toBe(280 * 40_000);
});

it('prices actual usage with the rates carried by the generation', function () {
    $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184];

    expect((new PriceEstimator)->cost($usage, pricing()['rates']))
        ->toBe(12 * 5000 + 765 * 10000 + 6450 * 40000 + 3184 * 12500);

    // a later price change does not matter: the generation's own rates win
    $newRates = ['input_text_tokens' => '6.00'] + pricing()['rates'];
    expect((new PriceEstimator)->cost($usage, $newRates))->toBe(12 * 6000 + 765 * 10000 + 6450 * 40000 + 3184 * 12500);
});

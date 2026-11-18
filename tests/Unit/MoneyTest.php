<?php

use App\Support\Money;

it('converts decimals to nano-dollars without floats', function () {
    expect(Money::fromDecimal('0.046125000'))->toBe(46_125_000)
        ->and(Money::fromDecimal('0.169080000'))->toBe(169_080_000)
        ->and(Money::fromDecimal('5'))->toBe(5_000_000_000)
        ->and(Money::fromDecimal('0.000000001'))->toBe(1)
        ->and(Money::toDecimal(373_875_000))->toBe('0.373875000')
        ->and(Money::toDecimal(Money::fromDecimal('123.456789012'.'')))->toBe('123.456789012');
});

it('derives integer per-token costs from per-1M rates', function () {
    expect(Money::tokenCost(1, '5.00'))->toBe(5000)
        ->and(Money::tokenCost(1, '10.00'))->toBe(10000)
        ->and(Money::tokenCost(1, '40.00'))->toBe(40000)
        ->and(Money::tokenCost(1, '12.50'))->toBe(12500);
});

it('adds the 25% markup exactly', function () {
    expect(Money::withMarkup(373_875_000))->toBe(467_343_750)
        ->and(Money::withMarkup(0))->toBe(0)
        ->and(Money::withMarkup(1))->toBe(2); // never rounds in the user's favour below cost
});

it('formats for display only', function () {
    expect(Money::format(373_875_000))->toBe('$0.373875')
        ->and(Money::format(5_000_000_000))->toBe('$5.00')
        ->and(Money::format(0))->toBe('$0.00')
        ->and(Money::format(1_500_000))->toBe('$0.0015');
});

<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in the app is an integer number of nano-dollars (1e-9 USD).
 * Pixelforge reports costs with 9 decimals, so nano-dollars are exact. No floats anywhere.
 */
final class Money
{
    public const NANO = 1_000_000_000;

    /** Our markup over Pixelforge's cost: +25%. */
    private const MARKUP_PERCENT = 125;

    /** "0.046125000" -> 46125000. Accepts up to 9 decimals. */
    public static function fromDecimal(string $decimal): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,9}))?$/', trim($decimal), $m)) {
            throw new InvalidArgumentException("Invalid money amount: {$decimal}");
        }

        $nano = (int) $m[2] * self::NANO + (int) str_pad($m[3] ?? '', 9, '0');

        return $m[1] === '-' ? -$nano : $nano;
    }

    /** 46125000 -> "0.046125000" (exactly the format of Pixelforge's cost_usd). */
    public static function toDecimal(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $nano = abs($nano);

        return sprintf('%s%d.%09d', $sign, intdiv($nano, self::NANO), $nano % self::NANO);
    }

    /**
     * Cost of $tokens at a rate quoted in USD per 1M tokens ("12.50").
     * 1 token at $X per 1M costs X*1000 nano-dollars, an integer for any rate with <= 3 decimals.
     * For finer rates we round half up so the result is still deterministic.
     */
    public static function tokenCost(int $tokens, string $ratePerMillion): int
    {
        return intdiv($tokens * self::fromDecimal($ratePerMillion) + 500_000, 1_000_000);
    }

    /** What the user pays for a given Pixelforge cost (cost x 1.25, rounded up to the nano-dollar). */
    public static function withMarkup(int $providerCost): int
    {
        return intdiv($providerCost * self::MARKUP_PERCENT + 99, 100);
    }

    /** Display only: "$0.373875", "$5.00". Rounds half up to 6 decimals and keeps at least 2. */
    public static function format(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $micro = intdiv(abs($nano) + 500, 1000);
        $str = sprintf('%d.%06d', intdiv($micro, 1_000_000), $micro % 1_000_000);
        $str = rtrim($str, '0');
        if (strlen(substr($str, strpos($str, '.') + 1)) < 2) {
            $str = str_pad($str, strpos($str, '.') + 3, '0');
        }

        return $sign.'$'.$str;
    }
}

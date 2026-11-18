<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Estimates a generation's cost from the /v1/pricing document, before anything is sent.
 * Output tokens and image tokens are exact; text tokens (+-15%) and reasoning tokens
 * (a quality-dependent range) are only known once the generation finishes, hence min..max.
 */
class PriceEstimator
{
    /**
     * @param  array  $pricing  decoded GET /v1/pricing
     * @return array{provider_min:int, provider_max:int, user_min:int, user_max:int, tokens:array}
     */
    public function estimate(array $pricing, string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $t = $pricing['tokens'];
        $textEstimate = intdiv(mb_strlen($prompt) + $t['characters_per_text_token'] - 1, $t['characters_per_text_token']);
        $variance = (int) ceil($textEstimate * (int) round($t['text_token_variance'] * 1000) / 1000);

        $min = [
            'input_text_tokens' => max(0, $textEstimate - $variance),
            'input_image_tokens' => $referenceImages * $t['per_reference_image'],
            'output_tokens' => $t['output'][$quality][$size],
            'reasoning_tokens' => $t['reasoning'][$quality]['min'],
        ];
        $max = [
            'input_text_tokens' => $textEstimate + $variance,
            'reasoning_tokens' => $t['reasoning'][$quality]['max'],
        ] + $min;

        $providerMin = $this->cost($min, $pricing['rates']);
        $providerMax = $this->cost($max, $pricing['rates']);

        return [
            'provider_min' => $providerMin,
            'provider_max' => $providerMax,
            'user_min' => Money::withMarkup($providerMin),
            'user_max' => Money::withMarkup($providerMax),
            'tokens' => ['min' => $min, 'max' => $max],
        ];
    }

    /** Cost in nano-dollars of a usage array (token counts keyed like the rates). */
    public function cost(array $usage, array $rates): int
    {
        $total = 0;
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $kind) {
            $total += Money::tokenCost((int) ($usage[$kind] ?? 0), $rates[$kind]);
        }

        return $total;
    }
}

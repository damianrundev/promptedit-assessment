<?php

namespace App\Support;

/** Plain-language explanations for Pixelforge error codes (and our own give-up codes). */
final class ErrorMessages
{
    private const MESSAGES = [
        'content_policy_violation' => 'Your prompt was blocked by the content policy. Try rewording it. You were not charged.',
        'validation_failed' => 'Pixelforge rejected the request as invalid. You were not charged.',
        'daily_limit_exceeded' => 'The daily generation quota has been used up. Try again tomorrow (UTC). You were not charged.',
        'rate_limit_exceeded' => 'We are sending requests too fast for Pixelforge and gave up after several retries. You were not charged.',
        'concurrency_limit_exceeded' => 'Too many generations were running at once and no slot freed up in time. You were not charged.',
        'overloaded' => 'Pixelforge is overloaded right now and did not recover after several retries. You were not charged.',
        'provider_unavailable' => 'We could not reach Pixelforge after several retries. You were not charged.',
        'generation_failed' => 'The model could not produce an image for this prompt. You were not charged.',
        'internal_error' => 'Something went wrong on Pixelforge\'s side while generating. You were not charged; please retry.',
        'invalid_api_key' => 'The service is misconfigured (invalid API key). You were not charged.',
    ];

    /** @return array{code: ?string, message: string} */
    public static function for(?string $code, ?string $providerMessage = null): array
    {
        return [
            'code' => $code,
            'message' => self::MESSAGES[$code] ?? ($providerMessage ? "$providerMessage You were not charged." : 'The generation failed. You were not charged.'),
        ];
    }
}

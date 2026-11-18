<?php

namespace App\Services\Pixelforge;

/** Verifies `Pixelforge-Signature: t=<unix>,v1=<hex hmac>` over "<t>.<raw body>". */
class WebhookSignature
{
    private const TOLERANCE_SECONDS = 300;

    public function verify(string $rawBody, ?string $header, ?int $now = null): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $parts = [];
        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        if ($secret === '' || $timestamp === 0 || abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret), $parts['v1'] ?? '');
    }
}

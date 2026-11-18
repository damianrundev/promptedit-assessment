<?php

namespace App\Services\Pixelforge;

/** Thin value object over a Pixelforge HTTP response (or a transport failure, status 0). */
final class ApiResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $body = [],
        public readonly ?int $retryAfter = null,
        public readonly bool $replayed = false,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Branch on this, not on the HTTP status. Null for transport failures and non-JSON errors. */
    public function code(): ?string
    {
        return $this->body['error']['code'] ?? null;
    }

    public function message(): ?string
    {
        return $this->body['error']['message'] ?? null;
    }
}

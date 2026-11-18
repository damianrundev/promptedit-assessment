<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    private const PRICING_TTL = 300;

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /** Idempotent: the same key + params always returns the same generation. */
    public function createGeneration(array $payload, string $idempotencyKey): ApiResponse
    {
        return $this->send(fn () => $this->http()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/generations', $payload));
    }

    public function getGeneration(string $id): ApiResponse
    {
        return $this->send(fn () => $this->http()->get("/generations/{$id}"));
    }

    public function usage(string $startDate, string $endDate): ApiResponse
    {
        return $this->send(fn () => $this->http()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]));
    }

    /** Live rates + token model, cached a few minutes. A stale copy is kept as a fallback. */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', self::PRICING_TTL, function () {
            try {
                $response = Http::baseUrl(config('services.pixelforge.base_url'))->acceptJson()->timeout(10)->get('/pricing');
            } catch (ConnectionException $e) {
                $response = null;
            }

            if ($response?->successful()) {
                Cache::forever('pixelforge.pricing.stale', $response->json());

                return $response->json();
            }

            return Cache::get('pixelforge.pricing.stale')
                ?? throw new \RuntimeException('Pixelforge pricing is unavailable.');
        });
    }

    private function send(callable $request): ApiResponse
    {
        try {
            /** @var Response $response */
            $response = $request();
        } catch (ConnectionException) {
            return new ApiResponse(0);
        }

        return new ApiResponse(
            $response->status(),
            $response->json() ?? [],
            $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : null,
            $response->header('Idempotent-Replayed') === 'true',
        );
    }
}

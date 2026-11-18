<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Pixelforge image URLs expire after 24h, so we keep our own copy and serve that. */
class DownloadGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public string $generationId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(PixelforgeClient $client): void
    {
        $g = Generation::find($this->generationId);
        if (! $g || $g->status !== Generation::SUCCEEDED || $g->image_path || ! $g->image_url) {
            return;
        }

        $response = Http::timeout(30)->get($g->image_url);

        if (! $response->successful() && $g->pixelforge_id) {
            // The signed URL may have been refreshed since we stored it.
            $fresh = $client->getGeneration($g->pixelforge_id)->body['image_url'] ?? null;
            $response = $fresh ? Http::timeout(30)->get($fresh) : $response;
        }

        $response->throw();

        $path = "generations/{$g->id}.png";
        Storage::disk('local')->put($path, $response->body());
        $g->update(['image_path' => $path]);
    }
}

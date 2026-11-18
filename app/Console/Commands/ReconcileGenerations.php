<?php

namespace App\Console\Commands;

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;

/** Backup for lost webhooks: poll generations that have been active for a while, using the same applier as the webhook. */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile {--older-than=2 : minutes a generation must have been active}';

    protected $description = 'Poll Pixelforge for generations whose webhook never arrived';

    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): int
    {
        $stale = Generation::whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->whereNotNull('pixelforge_id')
            ->where('created_at', '<', now()->subMinutes((int) $this->option('older-than')))
            ->orderBy('created_at')
            ->limit(40) // stay far below the 60 requests/minute limit
            ->get();

        foreach ($stale as $generation) {
            $response = $client->getGeneration($generation->pixelforge_id);
            if ($response->ok()) {
                $applier->apply($response->body);
            }
        }

        // Image downloads that failed or were never queued (URLs live 24h).
        Generation::where('status', Generation::SUCCEEDED)->whereNull('image_path')
            ->where('image_url_expires_at', '>', now())
            ->each(fn (Generation $g) => DownloadGenerationImage::dispatch($g->id));

        $this->info("Polled {$stale->count()} active generation(s).");

        return self::SUCCESS;
    }
}

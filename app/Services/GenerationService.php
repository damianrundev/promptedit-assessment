<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PriceEstimator;
use Illuminate\Support\Facades\DB;

/** Entry point for creating a generation: estimate, reserve credits, record locally, then hand off to the queue. */
class GenerationService
{
    public function __construct(
        private PixelforgeClient $client,
        private PriceEstimator $estimator,
        private Ledger $ledger,
    ) {}

    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): array
    {
        return $this->estimator->estimate($this->client->pricing(), $prompt, $size, $quality, $referenceImages);
    }

    /**
     * @param  array{prompt:string,size:string,quality:string,reference_images?:array}  $data
     *
     * @throws InsufficientCreditsException
     */
    public function create(User $user, array $data): Generation
    {
        $refs = array_values($data['reference_images'] ?? []);
        $estimate = $this->estimate($data['prompt'], $data['size'], $data['quality'], count($refs));

        $generation = DB::transaction(function () use ($user, $data, $refs, $estimate) {
            // Serialise concurrent submissions of the same user so two requests can't both spend the same balance.
            User::whereKey($user->id)->lockForUpdate()->first();

            $balance = $user->balanceNano();
            if ($balance < $estimate['user_max']) {
                throw new InsufficientCreditsException($estimate['user_max'], $balance);
            }

            $generation = Generation::create([
                'user_id' => $user->id,
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $refs,
                'est_min_nano' => $estimate['user_min'],
                'est_max_nano' => $estimate['user_max'],
                'reserved_nano' => $estimate['user_max'],
                'status' => Generation::PENDING,
            ]);
            $this->ledger->reserve($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation->id);

        return $generation;
    }
}

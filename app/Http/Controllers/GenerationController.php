<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\GenerationRequest;
use App\Models\Generation;
use App\Services\GenerationService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Generations/Create', [
            'sizes' => GenerationRequest::SIZES,
            'qualities' => GenerationRequest::QUALITIES,
            'markup' => '25%',
        ]);
    }

    /** Live price estimate for the form. Server-side so the browser never does money math. */
    public function estimate(Request $request, GenerationService $service): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', 'in:'.implode(',', GenerationRequest::SIZES)],
            'quality' => ['required', 'in:'.implode(',', GenerationRequest::QUALITIES)],
            'reference_images' => ['nullable', 'array', 'max:4'],
        ]);

        $e = $service->estimate($data['prompt'] ?? '', $data['size'], $data['quality'], count($data['reference_images'] ?? []));
        $balance = $request->user()->balanceNano();

        return response()->json([
            'min' => Money::format($e['user_min']),
            'max' => Money::format($e['user_max']),
            'tokens' => $e['tokens'],
            'balance' => Money::format($balance),
            'can_afford' => $balance >= $e['user_max'],
        ]);
    }

    public function store(GenerationRequest $request, GenerationService $service)
    {
        try {
            $service->create($request->user(), $request->validated());
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors([
                'credits' => 'You need at least '.Money::format($e->requiredNano).' (the maximum this generation could cost) but your balance is '.Money::format($e->balanceNano).'. Buy credits to continue.',
            ]);
        }

        return redirect()->route('generations.index');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Generations/Index', [
            'generations' => fn () => $request->user()->generations()->latest()->limit(100)->get()->map->present(),
        ]);
    }

    public function image(Request $request, Generation $generation): StreamedResponse
    {
        abort_unless($generation->user_id === $request->user()->id || $request->user()->is_admin, 404);
        abort_unless($generation->image_path && Storage::disk('local')->exists($generation->image_path), 404);

        return Storage::disk('local')->response($generation->image_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}

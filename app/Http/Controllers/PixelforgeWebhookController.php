<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationStateApplier $applier): JsonResponse
    {
        $raw = $request->getContent(); // verify against the raw body, before any decoding
        abort_unless($signature->verify($raw, $request->header('Pixelforge-Signature')), 400, 'Invalid Pixelforge signature.');

        $event = json_decode($raw, true);
        abort_unless(is_array($event) && isset($event['id'], $event['type']), 400, 'Malformed event.');

        // One transaction: dedupe by event id, lock the generation, apply. If anything throws, the
        // event row rolls back too, so Pixelforge's retry is processed from scratch rather than lost.
        DB::transaction(function () use ($event, $applier) {
            $inserted = WebhookEvent::insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'payload' => json_encode($event),
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return; // duplicate delivery: already handled
            }

            if (str_starts_with($event['type'], 'generation.') && is_array($event['data']['object'] ?? null)) {
                $generation = $applier->apply($event['data']['object']);
                WebhookEvent::where('event_id', $event['id'])->update(['generation_id' => $generation?->id]);
            }
        });

        return response()->json(['received' => true]);
    }
}

<?php

use App\Models\WebhookEvent;

it('accepts a valid signature', function () {
    postWebhook(['id' => 'evt_1', 'type' => 'ping', 'data' => ['object' => []]])->assertOk();
    expect(WebhookEvent::where('event_id', 'evt_1')->exists())->toBeTrue(); // ping is accepted and recorded
});

it('rejects an invalid signature', function () {
    $body = json_encode(['id' => 'evt_2', 'type' => 'ping']);

    postWebhook($body, signature($body, secret: 'whsec_wrong'))->assertStatus(400);
    postWebhook($body, 'garbage')->assertStatus(400);
    postWebhook($body, signature('{"tampered":true}'))->assertStatus(400);
    expect(WebhookEvent::count())->toBe(0);
});

it('rejects an expired timestamp (replay)', function () {
    $body = json_encode(['id' => 'evt_3', 'type' => 'ping']);

    postWebhook($body, signature($body, time() - 301))->assertStatus(400);
    postWebhook($body, signature($body, time() - 299))->assertOk();
});

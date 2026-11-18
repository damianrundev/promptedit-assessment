<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helpers shared by the Pixelforge tests
|--------------------------------------------------------------------------
*/

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const PF = 'pixelforge.promptedit.dev/v1';

function pfPricing(): array
{
    return [
        'object' => 'pricing', 'currency' => 'usd', 'unit' => 'per_1m_tokens',
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
        ],
    ];
}

function fakePricing(): void
{
    Http::fake([PF.'/pricing' => Http::response(pfPricing())]);
}

/** A user with credits (in USD). */
function userWithCredits(float|int $usd = 10, array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    app(Ledger::class)->purchase($user, (int) round($usd * Money::NANO));

    return $user;
}

/** Creates a generation through the real service (reserves credits). Jobs are not run. */
function newGeneration(User $user, array $data = []): Generation
{
    Queue::fake();
    Http::fake([PF.'/pricing' => Http::response(pfPricing())]);

    return app(GenerationService::class)->create($user, $data + ['prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium']);
}

/** A Pixelforge generation object as it appears in API responses and webhooks. */
function remoteGeneration(Generation $g, string $status, array $over = []): array
{
    $base = [
        'id' => $g->pixelforge_id ?? 'gen_TEST'.substr(md5($g->id), 0, 10),
        'object' => 'generation', 'status' => $status, 'prompt' => $g->prompt, 'size' => $g->size, 'quality' => $g->quality,
        'reference_images' => $g->reference_images, 'metadata' => ['local_id' => $g->id],
        'image_url' => null, 'image_url_expires_at' => null, 'usage' => null, 'rates' => pfPricing()['rates'],
        'error' => null, 'created_at' => '2026-11-04T14:03:11Z', 'started_at' => null, 'completed_at' => null,
    ];
    if ($status === 'succeeded') {
        $base['usage'] = ['input_text_tokens' => 4, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2104];
        $base['image_url'] = 'https://pixelforge.promptedit.dev/images/x.png?sig=1';
        $base['image_url_expires_at'] = '2026-11-05T14:03:29Z';
        $base['completed_at'] = '2026-11-04T14:03:29Z';
        $base['started_at'] = '2026-11-04T14:03:13Z';
    }
    if ($status === 'failed') {
        $base['error'] = ['code' => 'internal_error', 'message' => 'Something went wrong on our side while generating.'];
        $base['completed_at'] = '2026-11-04T14:03:29Z';
    }

    return $over + $base;
}

/** Provider cost of the default succeeded usage above: 4*5000 + 1100*40000 + 1000*12500 nano-$ */
const DEFAULT_PROVIDER_COST = 4 * 5000 + 1100 * 40000 + 1000 * 12500;

function webhookEvent(string $id, string $type, array $generation): array
{
    return ['id' => $id, 'object' => 'event', 'type' => $type, 'created_at' => '2026-11-04T14:03:29Z', 'data' => ['object' => $generation]];
}

function signature(string $body, ?int $t = null, string $secret = 'whsec_test'): string
{
    $t ??= time();

    return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
}

function postWebhook(array|string $event, ?string $signature = null): Illuminate\Testing\TestResponse
{
    $body = is_string($event) ? $event : json_encode($event);

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature ?? signature($body),
    ], $body);
}

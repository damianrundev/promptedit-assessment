<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/** Creates the demo admin and fires one of every behaviour through the normal GenerationService (no direct API calls). */
class DemoGenerations extends Command
{
    protected $signature = 'demo:generations {--email=admin@promptedit.test} {--password=} {--credits=50}';

    protected $description = 'Create the demo admin user (with credits) and submit the verification generations';

    public function handle(GenerationService $service, Ledger $ledger): int
    {
        $user = User::firstOrNew(['email' => $this->option('email')]);
        if (! $user->exists) {
            $user->fill(['name' => 'Demo Admin', 'password' => Hash::make($this->option('password') ?: 'change-me'), 'email_verified_at' => now()]);
        }
        $user->is_admin = true;
        $user->save();

        if ($user->balanceNano() < 20 * Money::NANO) {
            $ledger->purchase($user, (int) $this->option('credits') * Money::NANO, 'Demo credits');
        }

        $png = 'https://picsum.photos/seed/';
        $cases = [
            ['A lighthouse on a cliff at dawn, oil painting [succeed]', '1024x1024', 'low', []],
            ['A fox reading a book in a cozy library [succeed]', '1536x1024', 'medium', []],
            ['Futuristic city skyline at dusk, neon reflections [succeed]', '1024x1536', 'high', [$png.'a/400', $png.'b/400']],
            ['Minimalist poster of a mountain range, flat colors [succeed]', '1536x1024', 'high', []],
            ['This one is meant to fail [fail]', '1024x1024', 'medium', []],
            ['This one is delivered twice [duplicate] [succeed]', '1024x1024', 'medium', []],
            ['This one is slow [slow] [succeed]', '1024x1024', 'low', []],
            ['This prompt is blocked [blocked]', '1024x1024', 'low', []],
            ['Pixelforge will be overloaded [overloaded]', '1024x1024', 'low', []],
        ];

        foreach ($cases as [$prompt, $size, $quality, $refs]) {
            $g = $service->create($user, ['prompt' => $prompt, 'size' => $size, 'quality' => $quality, 'reference_images' => $refs]);
            $this->line("{$g->id}  {$quality} {$size}  {$prompt}");
        }

        return self::SUCCESS;
    }
}

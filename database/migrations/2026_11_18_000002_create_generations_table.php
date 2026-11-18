<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $t) {
            $t->uuid('id')->primary();                       // also sent to Pixelforge as Idempotency-Key + metadata.local_id
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('pixelforge_id')->nullable()->unique();
            // pending (not yet accepted), queued, processing, succeeded, failed, rejected (never accepted, free)
            $t->string('status')->default('pending')->index();
            $t->text('prompt');
            $t->string('size');
            $t->string('quality');
            $t->json('reference_images');

            // All money is integer nano-dollars (1e-9 USD). "user" = what we charge; "provider" = what Pixelforge bills us.
            $t->bigInteger('est_min_nano');                  // user price, estimate lower bound
            $t->bigInteger('est_max_nano');                  // user price, estimate upper bound (= amount reserved)
            $t->bigInteger('reserved_nano');
            $t->bigInteger('charged_nano')->nullable();      // user price, real
            $t->bigInteger('provider_cost_nano')->nullable();// Pixelforge cost, real

            $t->json('usage')->nullable();
            $t->json('rates')->nullable();                   // rates fixed by Pixelforge at creation time
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->string('note')->nullable();                  // transient hint, e.g. "retrying"
            $t->unsignedSmallInteger('submit_retries')->default(0);

            $t->text('image_url')->nullable();
            $t->timestamp('image_url_expires_at')->nullable();
            $t->string('image_path')->nullable();

            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable()->index(); // Pixelforge's completion time (UTC); drives daily attribution
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};

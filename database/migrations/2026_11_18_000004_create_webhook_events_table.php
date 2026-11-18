<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_id')->unique();    // evt_… : the deduplication key
            $t->string('type');
            $t->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $t->json('payload');
            $t->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};

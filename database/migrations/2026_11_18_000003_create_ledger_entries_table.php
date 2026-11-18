<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('type');                  // purchase | reserve | release | charge
            $t->bigInteger('amount_nano');       // signed delta to the user's available balance
            $t->string('description')->nullable();
            $t->timestamp('created_at')->useCurrent();

            // Hard guarantee: a generation can be reserved, released and charged at most once each.
            // (NULL generation_id, i.e. purchases, never collide in PostgreSQL.)
            $t->unique(['generation_id', 'type']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};

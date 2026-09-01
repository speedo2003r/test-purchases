<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('provider_event_id')->unique();
            $table->string('event_type'); // success|failed|cancelled
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};

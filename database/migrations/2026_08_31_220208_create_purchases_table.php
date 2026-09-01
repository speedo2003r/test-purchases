<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending|confirmed|failed|cancelled
            $table->string('request_key')->unique();
            $table->unsignedBigInteger('current_attempt_id')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'status']);
            $table->index(['status', 'hold_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};

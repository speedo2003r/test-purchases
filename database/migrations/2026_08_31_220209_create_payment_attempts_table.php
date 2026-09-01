<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('provider_reference')->unique();
            $table->string('status')->default('pending'); // pending|succeeded|failed|cancelled
            $table->timestamps();

            $table->unique(['purchase_id', 'attempt_no']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('current_attempt_id')
                ->references('id')->on('payment_attempts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['current_attempt_id']);
        });

        Schema::dropIfExists('payment_attempts');
    }
};

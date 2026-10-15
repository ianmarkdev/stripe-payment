<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stripe_session_id')->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('status')->default('pending'); // pending | succeeded | failed
            $table->unsignedBigInteger('amount'); // minor units
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

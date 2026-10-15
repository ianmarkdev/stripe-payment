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
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            // auto:{payment_id} for automatic refunds, or the client's Idempotency-Key for requested ones
            $table->string('idempotency_key')->unique();
            $table->string('reason'); // duplicate_payment | order_cancelled | requested
            $table->unsignedBigInteger('amount'); // minor units
            $table->char('currency', 3); // ISO 4217, lowercase
            $table->string('status')->default('pending'); // pending | submitted | succeeded | failed
            $table->string('stripe_refund_id')->nullable()->unique(); // set once
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']); // refund worker: due pending refunds
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};

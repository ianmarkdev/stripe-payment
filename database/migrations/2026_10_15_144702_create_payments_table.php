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
            // Payments are financial history: an order with payments can't be deleted, only cancelled.
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->string('stripe_session_id')->unique(); // one row per Checkout Session attempt
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('status')->default('pending'); // pending | succeeded | failed | expired
            $table->unsignedBigInteger('amount'); // minor units this attempt was created to collect
            $table->char('currency', 3); // ISO 4217, lowercase
            $table->timestamps();

            $table->unique(['order_id', 'id']); // target of orders.paid_payment_id composite FK
        });

        // paid_payment_id must point at a payment of the same order. Skipped while it is NULL.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['id', 'paid_payment_id'], 'orders_paid_payment_foreign')
                ->references(['order_id', 'id'])
                ->on('payments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_paid_payment_foreign');
        });

        Schema::dropIfExists('payments');
    }
};

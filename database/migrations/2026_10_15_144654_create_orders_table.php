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
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->string('status')->default('awaiting_payment'); // awaiting_payment | paid | cancelled
            $table->unsignedBigInteger('price'); // minor units (cents), snapshot at order creation
            $table->char('currency', 3); // ISO 4217, lowercase as Stripe returns it (e.g. gbp)
            // The single winning payment. Its composite FK (same order only) is added in the
            // payments migration, because the payments table doesn't exist yet.
            $table->uuid('paid_payment_id')->nullable()->unique();
            $table->string('cart_id')->index();
            $table->json('line_items');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

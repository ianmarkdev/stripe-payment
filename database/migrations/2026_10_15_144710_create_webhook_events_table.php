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
        Schema::create('webhook_events', function (Blueprint $table) {
             $table->uuid('id')->primary();
            $table->string('stripe_event_id')->unique(); // blocks duplicate Stripe events
            $table->timestamp('received_at');
            $table->json('payload');
            $table->string('status')->default('received'); // received | processed | ignored | failed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};

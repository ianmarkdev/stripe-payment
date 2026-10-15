<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasUuids;

    protected $fillable = [
        'payment_id', 'idempotency_key', 'reason', 'amount', 'currency', 'status',
        'stripe_refund_id', 'attempts', 'last_error', 'next_attempt_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'attempts' => 'integer', 'next_attempt_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

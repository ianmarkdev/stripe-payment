<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = ['stripe_event_id', 'type', 'received_at', 'payload', 'status', 'attempts', 'last_error', 'next_attempt_at', 'processed_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}

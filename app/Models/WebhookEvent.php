<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = ['stripe_event_id', 'received_at', 'payload', 'status'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime'];
    }
}

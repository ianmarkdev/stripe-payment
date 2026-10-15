<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasUuids;

    protected $fillable = ['order_number', 'status', 'price', 'cart_id', 'line_items'];

    protected function casts(): array
    {
        return ['line_items' => 'array', 'price' => 'integer'];
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}

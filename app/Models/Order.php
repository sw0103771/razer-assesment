<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'gateway_payment_id',
        'idempotency_key',
    ];
}

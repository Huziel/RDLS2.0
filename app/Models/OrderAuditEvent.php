<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'store_id', 'actor_id', 'actor_type', 'event_type', 'event_key',
        'request_hash', 'previous', 'next', 'response_status', 'response_data', 'created_at',
    ];

    protected $casts = [
        'previous' => 'array',
        'next' => 'array',
        'response_data' => 'array',
        'created_at' => 'datetime',
    ];
}

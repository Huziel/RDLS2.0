<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Layaway extends Model
{
    protected $fillable = ['store_id', 'client_id', 'client_name', 'client_phone', 'total', 'paid', 'pending', 'products', 'status', 'notes'];
    protected $casts = ['products' => 'array', 'total' => 'float', 'paid' => 'float', 'pending' => 'float'];

    public function client() { return $this->belongsTo(Client::class); }
    public function store() { return $this->belongsTo(Store::class, 'store_id'); }
}

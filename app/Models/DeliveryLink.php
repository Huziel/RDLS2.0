<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryLink extends Model
{
    protected $table = 'anexosdeliver';
    public $timestamps = false;
    protected $fillable = ['deliveryMan', 'store', 'bloqueo'];

    public function deliver() { return $this->belongsTo(User::class, 'deliveryMan'); }
    public function store() { return $this->belongsTo(Store::class, 'store'); }
}

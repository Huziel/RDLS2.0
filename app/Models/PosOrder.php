<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrder extends Model
{
    protected $table = 'pventageneral';

    public $timestamps = false;

    protected $fillable = [
        'noOrder', 'nombre', 'fecha', 'estado', 'total', 'extra', 'descuento', 'tipoPago', 'creator',
    ];

    public function details()
    {
        return $this->hasMany(PosOrderDetail::class, 'idPventaGeneral');
    }

    public function scopeActive($query, $creator)
    {
        return $query->where('creator', $creator)->whereIn('estado', [0, 1]);
    }
}

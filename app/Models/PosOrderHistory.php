<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderHistory extends Model
{
    protected $table = 'pventageneralhisto';

    public $timestamps = false;

    protected $fillable = [
        'noOrder', 'nombre', 'fecha', 'estado', 'total', 'extra', 'descuento', 'tipoPago', 'creator',
    ];

    public function details()
    {
        return $this->hasMany(PosOrderDetailHistory::class, 'idPventaGeneral');
    }
}

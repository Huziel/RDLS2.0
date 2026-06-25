<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderDetail extends Model
{
    protected $table = 'pventageneraldetalle';

    public $timestamps = false;

    protected $fillable = [
        'idPventaGeneral', 'productoId', 'cantidad', 'nameProd', 'precioBruto', 'precioNeto',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderDetailHistory extends Model
{
    protected $table = 'pventageneraldetallehisto';

    public $timestamps = false;

    protected $fillable = [
        'idPventaGeneral', 'productoId', 'cantidad', 'nameProd', 'precioBruto', 'precioNeto',
    ];
}

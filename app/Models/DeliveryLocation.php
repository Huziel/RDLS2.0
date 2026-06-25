<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryLocation extends Model
{
    protected $table = 'location';
    public $timestamps = false;
    protected $fillable = ['idDeliver', 'latitud', 'longitud', 'time'];
}

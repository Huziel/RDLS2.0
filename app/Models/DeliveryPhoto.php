<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryPhoto extends Model
{
    protected $table = 'fotoporfile';
    public $timestamps = false;
    protected $fillable = ['idUser', 'picture'];
}

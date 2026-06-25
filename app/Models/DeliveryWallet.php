<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryWallet extends Model
{
    protected $table = 'wallet';
    public $timestamps = false;
    protected $fillable = ['idLog', 'cant', 'time'];
}

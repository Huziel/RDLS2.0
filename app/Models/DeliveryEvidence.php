<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryEvidence extends Model
{
    protected $table = 'imageevidence';
    public $timestamps = false;
    protected $fillable = ['orderC', 'img'];
}

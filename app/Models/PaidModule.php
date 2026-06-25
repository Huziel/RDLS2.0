<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaidModule extends Model
{
    protected $table = 'paidmodules';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'module',
        'user',
        'status',
    ];
}

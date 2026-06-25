<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationCode extends Model
{
    protected $table = 'verificacion';
    public $timestamps = false;
    protected $fillable = ['orderC', 'code'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    protected $table = 'token';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'id_Log',
        'token',
        'fecha',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_Log');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    protected $table = 'deviceid';
    public $timestamps = false;
    protected $fillable = ['idLog', 'token'];
}

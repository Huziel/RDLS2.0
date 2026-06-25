<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasRoles;

    protected $table = 'log';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'keyvalue',
        'type',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected $hidden = [
        'keyvalue',
    ];

    protected $guard_name = 'web';

    public function getAuthPassword()
    {
        return $this->keyvalue;
    }

    public function store()
    {
        return $this->hasOne(Store::class, 'createdby', 'name');
    }

    public function deliveryProfile()
    {
        return $this->hasOne(DeliveryProfile::class, 'idLog');
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class, 'idLog');
    }

    public function passwordReset()
    {
        return $this->hasOne(PasswordReset::class, 'id_Log');
    }

    public function paidModules()
    {
        return $this->hasMany(PaidModule::class, 'user');
    }

    public function canAccessModule(string $module): bool
    {
        return $this->paidModules()
            ->where('module', $module)
            ->where('status', 1)
            ->exists();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $table = 'liks';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'serial',
        'time',
        'phone',
        'session',
        'createdby',
        'type',
        'category',
        'color',
        'adress',
        'logo',
        'locales',
        'logojpg',
        'paypal',
        'lat',
        'long',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'createdby', 'name');
    }

    public function extra()
    {
        return $this->hasOne(StoreExtra::class, 'idTienda');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'session', 'createdby');
    }
}

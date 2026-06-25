<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class MercadoPagoAccount extends Model { protected $table='mercadopagocuentas'; public $timestamps=false; protected $fillable=['idLog','secretKey','publicKey']; }

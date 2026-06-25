<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class MercadoPagoPayment extends Model { protected $table='mercadopago'; public $timestamps=false; protected $fillable=['orderP','status','preference','fecha']; }

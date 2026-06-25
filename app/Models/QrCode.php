<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class QrCode extends Model { protected $table='qr_codes'; protected $fillable=['store_id','type','target_url','target_id','label','scans','image_path']; }

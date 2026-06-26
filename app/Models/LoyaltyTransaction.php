<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    protected $fillable = ['store_id', 'client_id', 'points', 'type', 'description', 'reference'];

    public function client() { return $this->belongsTo(Client::class); }

    public static function log($storeId, $clientId, int $points, string $type, $desc = null, $ref = null): void
    {
        static::create(['store_id' => $storeId, 'client_id' => $clientId, 'points' => $points, 'type' => $type, 'description' => $desc, 'reference' => $ref]);
    }
}

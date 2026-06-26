<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPoint extends Model
{
    protected $fillable = ['store_id', 'client_id', 'points'];

    public function client() { return $this->belongsTo(Client::class); }

    public static function getBalance($storeId, $clientId): int
    {
        return (int) (static::where('store_id', $storeId)->where('client_id', $clientId)->value('points') ?? 0);
    }

    public static function addPoints($storeId, $clientId, int $points, string $type, string $desc = null, string $ref = null): void
    {
        $record = static::firstOrCreate(['store_id' => $storeId, 'client_id' => $clientId], ['points' => 0]);
        $record->increment('points', $points);
        LoyaltyTransaction::log($storeId, $clientId, $points, $type, $desc, $ref);
    }

    public static function redeemPoints($storeId, $clientId, int $points, string $ref = null): bool
    {
        $balance = static::getBalance($storeId, $clientId);
        if ($balance < $points) return false;
        static::where('store_id', $storeId)->where('client_id', $clientId)->decrement('points', $points);
        LoyaltyTransaction::log($storeId, $clientId, -$points, 'redeem', "Canje de $points puntos", $ref);
        return true;
    }
}

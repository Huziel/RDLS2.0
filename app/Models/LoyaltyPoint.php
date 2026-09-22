<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LoyaltyPoint extends Model
{
    protected $fillable = ['store_id', 'client_id', 'points'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public static function getBalance($storeId, $clientId): int
    {
        return (int) (static::where('store_id', $storeId)->where('client_id', $clientId)->value('points') ?? 0);
    }

    public static function addPoints($storeId, $clientId, int $points, string $type, ?string $desc = null, ?string $ref = null): bool
    {
        if ($points <= 0) {
            return false;
        }

        return DB::transaction(function () use ($storeId, $clientId, $points, $type, $desc, $ref) {
            DB::table('loyalty_points')->insertOrIgnore([
                'store_id' => $storeId,
                'client_id' => $clientId,
                'points' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $record = static::where('store_id', $storeId)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($ref !== null) {
                $existing = LoyaltyTransaction::where('store_id', $storeId)
                    ->where('type', $type)
                    ->where('reference', $ref)
                    ->first();
                if ($existing) {
                    return (int) $existing->client_id === (int) $clientId
                        && (int) $existing->points === $points;
                }
            }

            $record->update(['points' => $record->points + $points]);
            LoyaltyTransaction::log($storeId, $clientId, $points, $type, $desc, $ref);

            return true;
        });
    }

    public static function redeemPoints(
        $storeId,
        $clientId,
        int $points,
        ?string $ref = null,
        string $type = 'redeem',
    ): bool {
        if ($points <= 0) {
            return false;
        }

        return DB::transaction(function () use ($storeId, $clientId, $points, $ref, $type) {
            $record = static::where('store_id', $storeId)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return false;
            }

            if ($ref !== null) {
                $existing = LoyaltyTransaction::where('store_id', $storeId)
                    ->where('type', $type)
                    ->where('reference', $ref)
                    ->first();
                if ($existing) {
                    return (int) $existing->client_id === (int) $clientId
                        && (int) $existing->points === -$points;
                }
            }

            if ((int) $record->points < $points) {
                return false;
            }

            $record->update(['points' => (int) $record->points - $points]);
            LoyaltyTransaction::log($storeId, $clientId, -$points, $type, "Canje de {$points} puntos", $ref);

            return true;
        });
    }
}

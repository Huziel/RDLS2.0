<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;
use App\Models\Store;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    // Store owner: get config
    public function config(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        return response()->json(['data' => LoyaltyConfig::getConfig($store->id)]);
    }

    // Store owner: update config
    public function updateConfig(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $config = LoyaltyConfig::getConfig($store->id);
        $config->update($request->only(['points_per_peso', 'pesos_per_point', 'minimum_points_to_redeem', 'enabled']));
        return response()->json(['data' => $config->fresh(), 'message' => 'Configuracion guardada.']);
    }

    // Get client points (public, for checkout)
    public function clientPoints(Request $request)
    {
        $request->validate(['store_serial' => 'required|string', 'client_phone' => 'required|string']);
        $store = Store::where('serial', $request->store_serial)->firstOrFail();
        $client = Client::where('store_id', $store->id)->where('phone', $request->client_phone)->first();
        if (!$client) return response()->json(['data' => ['points' => 0, 'registered' => false]]);

        $points = LoyaltyPoint::getBalance($store->id, $client->id);
        $config = LoyaltyConfig::getConfig($store->id);
        return response()->json(['data' => [
            'points' => $points, 'registered' => true, 'client_id' => $client->id,
            'client_name' => $client->name,
            'can_redeem' => $points >= $config->minimum_points_to_redeem && $config->enabled,
            'minimum_to_redeem' => $config->minimum_points_to_redeem,
            'pesos_per_point' => $config->pesos_per_point,
        ]]);
    }

    // Redeem points at checkout
    public function redeem(Request $request)
    {
        $request->validate([
            'store_serial' => 'required|string', 'client_id' => 'required|integer', 'points' => 'required|integer|min:1',
        ]);
        $store = Store::where('serial', $request->store_serial)->firstOrFail();
        $config = LoyaltyConfig::getConfig($store->id);
        if (!$config->enabled) return response()->json(['message' => 'Programa de lealtad no disponible.'], 422);

        $balance = LoyaltyPoint::getBalance($store->id, $request->client_id);
        if ($balance < $request->points) return response()->json(['message' => 'Puntos insuficientes.'], 422);
        if ($request->points < $config->minimum_points_to_redeem) return response()->json(['message' => "Minimo {$config->minimum_points_to_redeem} puntos para canjear."], 422);

        $discount = floor($request->points / $config->pesos_per_point);
        $ok = LoyaltyPoint::redeemPoints($store->id, $request->client_id, $request->points, 'checkout');
        if (!$ok) return response()->json(['message' => 'Error al canjear puntos.'], 500);

        return response()->json(['data' => ['points_redeemed' => $request->points, 'discount' => $discount, 'remaining' => LoyaltyPoint::getBalance($store->id, $request->client_id)]]);
    }

    // Store owner: list clients with points
    public function clients(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $points = LoyaltyPoint::with('client')->where('store_id', $store->id)
            ->whereHas('client')->orderByDesc('points')->paginate(20);
        $config = LoyaltyConfig::getConfig($store->id);

        $data = $points->through(fn($p) => [
            'client_id' => $p->client_id, 'client_name' => $p->client->name, 'client_phone' => $p->client->phone,
            'points' => $p->points, 'discount_value' => floor($p->points / $config->pesos_per_point),
        ]);

        return response()->json(['data' => $data->items(), 'meta' => ['current_page' => $points->currentPage(), 'last_page' => $points->lastPage(), 'total' => $points->total()]]);
    }

    // Store owner: add/subtract points manually
    public function adjustPoints(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $request->validate(['client_id' => 'required|integer', 'points' => 'required|integer', 'description' => 'nullable|string']);
        if ($request->points > 0) {
            LoyaltyPoint::addPoints($store->id, $request->client_id, $request->points, 'manual', $request->description);
        } else {
            LoyaltyPoint::redeemPoints($store->id, $request->client_id, abs($request->points), 'manual');
        }
        return response()->json(['message' => 'Puntos actualizados.', 'data' => ['balance' => LoyaltyPoint::getBalance($store->id, $request->client_id)]]);
    }

    // Store owner: transactions history
    public function transactions(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $tx = LoyaltyTransaction::where('store_id', $store->id)->with('client:id,name,phone')
            ->when($request->client_id, fn($q) => $q->where('client_id', $request->client_id))
            ->orderByDesc('id')->paginate(30);
        return response()->json($tx);
    }
}

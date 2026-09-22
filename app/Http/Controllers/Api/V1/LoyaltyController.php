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
        $validated = $request->validate([
            'points_per_peso' => ['sometimes', 'integer', 'min:1'],
            'pesos_per_point' => ['sometimes', 'integer', 'min:1'],
            'minimum_points_to_redeem' => ['sometimes', 'integer', 'min:1'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $config = LoyaltyConfig::getConfig($store->id);
        $config->update($validated);

        return response()->json(['data' => $config->fresh(), 'message' => 'Configuracion guardada.']);
    }

    // Get client points (public, for checkout)
    public function clientPoints(Request $request)
    {
        $request->validate(['store_serial' => 'required|string', 'client_phone' => 'required|string']);
        $store = Store::where('serial', $request->store_serial)->firstOrFail();
        $client = Client::where('store_id', $store->id)->where('phone', $request->client_phone)->first();
        if (! $client) {
            return response()->json(['data' => ['points' => 0, 'registered' => false]]);
        }

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
            'idempotency_key' => 'required|string|max:100',
        ]);
        $store = Store::where('serial', $request->store_serial)->firstOrFail();
        $config = LoyaltyConfig::getConfig($store->id);
        if (! $config->enabled) {
            return response()->json(['message' => 'Programa de lealtad no disponible.'], 422);
        }
        if ($config->pesos_per_point <= 0) {
            return response()->json(['message' => 'Configuracion de lealtad invalida.'], 422);
        }

        $client = Client::where('store_id', $store->id)->find($request->client_id);
        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }
        if ($request->points < $config->minimum_points_to_redeem) {
            return response()->json(['message' => "Minimo {$config->minimum_points_to_redeem} puntos para canjear."], 422);
        }

        $discount = floor($request->points / $config->pesos_per_point);
        $ok = LoyaltyPoint::redeemPoints(
            $store->id,
            $client->id,
            $request->points,
            'public:'.$request->idempotency_key,
            'checkout_redeem',
        );
        if (! $ok) {
            return response()->json(['message' => 'Puntos insuficientes.'], 422);
        }

        return response()->json(['data' => ['points_redeemed' => $request->points, 'discount' => $discount, 'remaining' => LoyaltyPoint::getBalance($store->id, $request->client_id)]]);
    }

    // Store owner: list clients with points
    public function clients(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $points = LoyaltyPoint::with('client')->where('store_id', $store->id)
            ->whereHas('client')->orderByDesc('points')->paginate(20);
        $config = LoyaltyConfig::getConfig($store->id);

        $data = $points->through(fn ($p) => [
            'client_id' => $p->client_id, 'client_name' => $p->client->name, 'client_phone' => $p->client->phone,
            'points' => $p->points,
            'discount_value' => $config->pesos_per_point > 0 ? floor($p->points / $config->pesos_per_point) : 0,
        ]);

        return response()->json(['data' => $data->items(), 'meta' => ['current_page' => $points->currentPage(), 'last_page' => $points->lastPage(), 'total' => $points->total()]]);
    }

    // Store owner: add/subtract points manually
    public function adjustPoints(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $request->validate(['client_id' => 'required|integer', 'points' => 'required|integer', 'description' => 'nullable|string']);
        $client = Client::where('store_id', $store->id)->findOrFail($request->client_id);
        if ($request->points > 0) {
            LoyaltyPoint::addPoints($store->id, $client->id, $request->points, 'manual', $request->description);
        } else {
            $redeemed = LoyaltyPoint::redeemPoints($store->id, $client->id, abs($request->points), null, 'manual');
            if (! $redeemed) {
                return response()->json(['message' => 'Puntos insuficientes.'], 422);
            }
        }

        return response()->json(['message' => 'Puntos actualizados.', 'data' => ['balance' => LoyaltyPoint::getBalance($store->id, $client->id)]]);
    }

    // Store owner: transactions history
    public function transactions(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $tx = LoyaltyTransaction::where('store_id', $store->id)->with('client:id,name,phone')
            ->when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->orderByDesc('id')->paginate(30);

        return response()->json($tx);
    }
}

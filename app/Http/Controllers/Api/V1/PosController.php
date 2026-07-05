<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Models\PosOrder;
use App\Models\PosOrderDetail;
use App\Models\PosOrderDetailHistory;
use App\Models\PosOrderHistory;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function activeOrders(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $orders = PosOrder::active($store->createdby)
            ->with('details')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function createOrder(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $request->validate([
            'nombre' => ['required', 'string'],
            'telefono' => ['nullable', 'string'],
        ]);

        $order = PosOrder::create([
            'noOrder' => now()->format('YmdHis') . rand(100, 999),
            'nombre' => $request->nombre,
            'telefono' => $request->telefono,
            'fecha' => now()->format('Y-m-d H:i:s'),
            'estado' => 0,
            'total' => 0,
            'extra' => 0,
            'descuento' => 0,
            'tipoPago' => 0,
            'creator' => $store->createdby,
        ]);

        return response()->json([
            'data' => $order->load('details'),
            'message' => 'Orden creada.',
        ], 201);
    }

    public function addProduct(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = PosOrder::where('creator', $store->createdby)
            ->where('estado', '0')
            ->findOrFail($orderId);

        $request->validate([
            'producto_id' => ['required', 'integer'],
            'cantidad' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::findOrFail($request->producto_id);

        $detail = PosOrderDetail::create([
            'idPventaGeneral' => $order->id,
            'productoId' => $product->id,
            'cantidad' => $request->cantidad,
            'nameProd' => $product->keyy,
            'precioBruto' => $product->number,
            'precioNeto' => $product->number * $request->cantidad,
        ]);

        return response()->json([
            'data' => $detail,
            'message' => 'Producto agregado.',
        ]);
    }

    public function updateProduct(Request $request, $orderId, $detailId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        PosOrder::where('creator', $store->createdby)->where('estado', '0')->findOrFail($orderId);

        $request->validate(['cantidad' => ['required', 'integer', 'min:1']]);

        $detail = PosOrderDetail::where('idPventaGeneral', $orderId)->findOrFail($detailId);
        $detail->update([
            'cantidad' => $request->cantidad,
            'precioNeto' => $detail->precioBruto * $request->cantidad,
        ]);

        return response()->json(['data' => $detail, 'message' => 'Actualizado.']);
    }

    public function removeProduct(Request $request, $orderId, $detailId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        PosOrder::where('creator', $store->createdby)->where('estado', '0')->findOrFail($orderId);
        PosOrderDetail::where('idPventaGeneral', $orderId)->where('id', $detailId)->delete();

        return response()->json(['message' => 'Producto eliminado.']);
    }

    public function deleteOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        PosOrder::where('creator', $store->createdby)->where('estado', '0')->findOrFail($orderId);
        PosOrderDetail::where('idPventaGeneral', $orderId)->delete();
        PosOrder::where('id', $orderId)->delete();

        return response()->json(['message' => 'Orden eliminada.']);
    }

    public function saveOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = PosOrder::where('creator', $store->createdby)
            ->where('estado', '0')
            ->with('details')
            ->findOrFail($orderId);

        $total = $order->details->sum('precioNeto');
        $extra = $request->input('extra', 0);

        $order->update([
            'fecha' => now()->format('Y-m-d H:i:s'),
            'estado' => 1,
            'total' => $total + $extra,
            'extra' => $extra,
        ]);

        return response()->json([
            'data' => $order->fresh('details'),
            'message' => 'Orden guardada.',
        ]);
    }

    public function payOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = PosOrder::where('creator', $store->createdby)
            ->where('estado', '1')
            ->with('details')
            ->findOrFail($orderId);

        $request->validate([
            'tipo_pago' => ['required', 'string', 'in:efectivo,tarjeta,transferencia'],
        ]);

        $paymentTypeMap = ['efectivo' => 1, 'tarjeta' => 2, 'transferencia' => 3];

        DB::transaction(function () use ($order, $request, $store, $paymentTypeMap) {
            // Move to history
            $history = PosOrderHistory::create([
                'noOrder' => $order->noOrder,
                'nombre' => $order->nombre,
                'telefono' => $order->telefono,
                'fecha' => now()->format('Y-m-d H:i:s'),
                'estado' => 2,
                'total' => $order->total,
                'extra' => $order->extra,
                'descuento' => $order->descuento,
                'tipoPago' => $paymentTypeMap[$request->tipo_pago],
                'creator' => $store->createdby,
            ]);

            foreach ($order->details as $detail) {
                PosOrderDetailHistory::create([
                    'idPventaGeneral' => $history->id,
                    'productoId' => $detail->productoId,
                    'cantidad' => $detail->cantidad,
                    'nameProd' => $detail->nameProd,
                    'precioBruto' => $detail->precioBruto,
                    'precioNeto' => $detail->precioNeto,
                ]);

                // Deduct stock
                $stock = \App\Models\ProductStock::where('idProd', $detail->productoId)->first();
                if ($stock) {
                    $stock->decrement('stock', $detail->cantidad);
                }
            }

            // Mark order as paid
            $order->update(['estado' => 2, 'tipoPago' => $paymentTypeMap[$request->tipo_pago]]);
        });

        // Earn loyalty points
        $this->earnPosLoyaltyPoints($order, $store);

        return response()->json(['message' => 'Orden pagada exitosamente.']);
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $orders = PosOrderHistory::where('creator', $store->createdby)
            ->with('details')
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 20));

        return response()->json($orders);
    }

    public function ticket($noOrder)
    {
        $user = request()->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = PosOrderHistory::with('details')
            ->where('creator', $store->createdby)
            ->where('noOrder', $noOrder)
            ->firstOrFail();

        return response()->json(['data' => $order]);
    }

    // Loyalty: check client points (POS)
    public function loyaltyCheck(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();
        $request->validate(['telefono' => 'required|string']);

        $client = Client::where('store_id', $store->id)->where('phone', $request->telefono)->first();
        if (!$client) {
            return response()->json(['data' => ['points' => 0, 'registered' => false]]);
        }

        $points = LoyaltyPoint::getBalance($store->id, $client->id);
        $config = LoyaltyConfig::getConfig($store->id);
        return response()->json(['data' => [
            'points' => $points,
            'registered' => true,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'can_redeem' => $points >= $config->minimum_points_to_redeem && $config->enabled,
            'minimum_to_redeem' => $config->minimum_points_to_redeem,
            'pesos_per_point' => $config->pesos_per_point,
            'points_per_peso' => $config->points_per_peso,
            'enabled' => $config->enabled,
        ]]);
    }

    // Loyalty: redeem points (POS)
    public function loyaltyRedeem(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();
        $request->validate([
            'client_id' => 'required|integer',
            'points' => 'required|integer|min:1',
        ]);

        $config = LoyaltyConfig::getConfig($store->id);
        if (!$config->enabled) {
            return response()->json(['message' => 'Programa de lealtad no disponible.'], 422);
        }

        $balance = LoyaltyPoint::getBalance($store->id, $request->client_id);
        if ($balance < $request->points) {
            return response()->json(['message' => 'Puntos insuficientes.'], 422);
        }
        if ($request->points < $config->minimum_points_to_redeem) {
            return response()->json(['message' => "Minimo {$config->minimum_points_to_redeem} puntos para canjear."], 422);
        }

        $discount = floor($request->points / $config->pesos_per_point);
        LoyaltyPoint::redeemPoints($store->id, $request->client_id, $request->points, 'pos');

        return response()->json(['data' => [
            'points_redeemed' => $request->points,
            'discount' => $discount,
            'remaining' => LoyaltyPoint::getBalance($store->id, $request->client_id),
        ]]);
    }

    private function earnPosLoyaltyPoints($order, $store)
    {
        try {
            if (!$order->telefono) return;
            $config = LoyaltyConfig::getConfig($store->id);
            if (!$config->enabled) return;

            $client = Client::where('store_id', $store->id)->where('phone', $order->telefono)->first();
            if (!$client) return;

            $points = (int) floor($order->total * $config->points_per_peso);
            if ($points > 0) {
                LoyaltyPoint::addPoints($store->id, $client->id, $points, 'earn', "Venta POS {$order->noOrder}", $order->noOrder);
            }
        } catch (\Exception $e) {}
    }
}

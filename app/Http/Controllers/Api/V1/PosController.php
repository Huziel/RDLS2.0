<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $orders = PosOrder::active($store->createdby)
            ->with('details')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function createOrder(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $request->validate(['nombre' => ['required', 'string']]);

        $order = PosOrder::create([
            'noOrder' => now()->format('YmdHis') . rand(100, 999),
            'nombre' => $request->nombre,
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
        $store = Store::where('createdby', $user->name)->firstOrFail();

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
        $store = Store::where('createdby', $user->name)->firstOrFail();

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
        $store = Store::where('createdby', $user->name)->firstOrFail();

        PosOrder::where('creator', $store->createdby)->where('estado', '0')->findOrFail($orderId);
        PosOrderDetail::where('idPventaGeneral', $orderId)->where('id', $detailId)->delete();

        return response()->json(['message' => 'Producto eliminado.']);
    }

    public function deleteOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        PosOrder::where('creator', $store->createdby)->where('estado', '0')->findOrFail($orderId);
        PosOrderDetail::where('idPventaGeneral', $orderId)->delete();
        PosOrder::where('id', $orderId)->delete();

        return response()->json(['message' => 'Orden eliminada.']);
    }

    public function saveOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

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
        $store = Store::where('createdby', $user->name)->firstOrFail();

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

        return response()->json(['message' => 'Orden pagada exitosamente.']);
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $orders = PosOrderHistory::where('creator', $store->createdby)
            ->with('details')
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 20));

        return response()->json($orders);
    }

    public function ticket($noOrder)
    {
        $user = request()->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $order = PosOrderHistory::with('details')
            ->where('creator', $store->createdby)
            ->where('noOrder', $noOrder)
            ->firstOrFail();

        return response()->json(['data' => $order]);
    }
}

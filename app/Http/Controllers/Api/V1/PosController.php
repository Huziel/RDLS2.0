<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\ConsumeInventory;
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
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function __construct(private readonly ConsumeInventory $consumeInventory) {}

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

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $order = PosOrder::create([
                    'noOrder' => now()->format('YmdHisv').random_int(100, 999),
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
                break;
            } catch (QueryException $exception) {
                $isDuplicate = in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1062], true);
                if (! $isDuplicate || $attempt === 3) {
                    throw $exception;
                }
            }
        }

        return response()->json([
            'data' => $order->load('details'),
            'message' => 'Orden creada.',
        ], 201);
    }

    public function addProduct(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $validated = $request->validate([
            'producto_id' => ['required', 'integer'],
            'cantidad' => ['required', 'integer', 'min:1'],
        ]);

        $detail = DB::transaction(function () use ($orderId, $store, $validated) {
            $order = PosOrder::where('creator', $store->createdby)
                ->where('estado', 0)
                ->lockForUpdate()
                ->findOrFail($orderId);
            $product = Product::byStore($store->createdby)
                ->active()
                ->findOrFail($validated['producto_id']);

            return PosOrderDetail::create([
                'idPventaGeneral' => $order->id,
                'productoId' => $product->id,
                'cantidad' => $validated['cantidad'],
                'nameProd' => $product->keyy,
                'precioBruto' => $product->number,
                'precioNeto' => $product->number * $validated['cantidad'],
            ]);
        });

        return response()->json([
            'data' => $detail,
            'message' => 'Producto agregado.',
        ]);
    }

    public function updateProduct(Request $request, $orderId, $detailId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $validated = $request->validate(['cantidad' => ['required', 'integer', 'min:1']]);

        $detail = DB::transaction(function () use ($orderId, $detailId, $store, $validated) {
            PosOrder::where('creator', $store->createdby)
                ->where('estado', 0)
                ->lockForUpdate()
                ->findOrFail($orderId);
            $detail = PosOrderDetail::where('idPventaGeneral', $orderId)->findOrFail($detailId);
            $detail->update([
                'cantidad' => $validated['cantidad'],
                'precioNeto' => $detail->precioBruto * $validated['cantidad'],
            ]);

            return $detail;
        });

        return response()->json(['data' => $detail, 'message' => 'Actualizado.']);
    }

    public function removeProduct(Request $request, $orderId, $detailId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        DB::transaction(function () use ($orderId, $detailId, $store) {
            PosOrder::where('creator', $store->createdby)
                ->where('estado', 0)
                ->lockForUpdate()
                ->findOrFail($orderId);
            PosOrderDetail::where('idPventaGeneral', $orderId)->where('id', $detailId)->delete();
        });

        return response()->json(['message' => 'Producto eliminado.']);
    }

    public function deleteOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        DB::transaction(function () use ($orderId, $store) {
            PosOrder::where('creator', $store->createdby)
                ->where('estado', 0)
                ->lockForUpdate()
                ->findOrFail($orderId);
            PosOrderDetail::where('idPventaGeneral', $orderId)->delete();
            PosOrder::where('id', $orderId)->where('estado', 0)->delete();
        });

        return response()->json(['message' => 'Orden eliminada.']);
    }

    public function saveOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $validated = $request->validate([
            'extra' => ['nullable', 'numeric', 'min:0'],
        ]);

        $order = DB::transaction(function () use ($orderId, $store, $validated) {
            $order = PosOrder::where('creator', $store->createdby)
                ->where('estado', 0)
                ->lockForUpdate()
                ->findOrFail($orderId);
            $details = PosOrderDetail::where('idPventaGeneral', $order->id)->get();

            if ($details->isEmpty()) {
                throw ValidationException::withMessages([
                    'order' => ['Agrega al menos un producto.'],
                ]);
            }

            $extra = $validated['extra'] ?? 0;
            $order->update([
                'fecha' => now()->format('Y-m-d H:i:s'),
                'estado' => 1,
                'total' => $details->sum('precioNeto') + $extra,
                'extra' => $extra,
            ]);

            return $order;
        });

        return response()->json([
            'data' => $order->fresh('details'),
            'message' => 'Orden guardada.',
        ]);
    }

    public function payOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $validated = $request->validate([
            'tipo_pago' => ['required', 'string', 'in:efectivo,tarjeta,transferencia'],
            'loyalty_points' => ['nullable', 'integer', 'min:0'],
        ]);

        $paymentTypeMap = ['efectivo' => 1, 'tarjeta' => 2, 'transferencia' => 3];

        $result = DB::transaction(function () use ($orderId, $validated, $store, $paymentTypeMap) {
            $order = PosOrder::where('creator', $store->createdby)
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ((int) $order->estado === 2) {
                $history = PosOrderHistory::where('creator', $store->createdby)
                    ->where('noOrder', $order->noOrder)
                    ->with('details')
                    ->firstOrFail();

                return ['history' => $history, 'idempotent' => true];
            }

            if ((int) $order->estado !== 1) {
                throw ValidationException::withMessages([
                    'order' => ['La orden debe guardarse antes de cobrar.'],
                ]);
            }

            $details = PosOrderDetail::where('idPventaGeneral', $order->id)->get();

            if ($details->isEmpty()) {
                throw ValidationException::withMessages([
                    'order' => ['La orden no contiene productos.'],
                ]);
            }

            $quantities = $details->groupBy('productoId')
                ->map(fn ($items) => (float) $items->sum('cantidad'));
            ($this->consumeInventory)($store->createdby, $quantities);

            $subtotal = (float) $details->sum(
                fn (PosOrderDetail $detail) => (float) $detail->precioBruto * (float) $detail->cantidad
            );
            $discount = $this->redeemPosLoyalty(
                $store,
                $order,
                (int) ($validated['loyalty_points'] ?? 0),
                $subtotal + (float) $order->extra,
            );
            $total = max(0, $subtotal + (float) $order->extra - $discount);
            $paymentType = $paymentTypeMap[$validated['tipo_pago']];
            $order->update([
                'total' => $total,
                'descuento' => $discount,
                'tipoPago' => $paymentType,
            ]);

            $history = PosOrderHistory::create([
                'noOrder' => $order->noOrder,
                'nombre' => $order->nombre,
                'telefono' => $order->telefono,
                'fecha' => now()->format('Y-m-d H:i:s'),
                'estado' => 2,
                'total' => $order->total,
                'extra' => $order->extra,
                'descuento' => $order->descuento,
                'tipoPago' => $paymentType,
                'creator' => $store->createdby,
            ]);

            foreach ($details as $detail) {
                PosOrderDetailHistory::create([
                    'idPventaGeneral' => $history->id,
                    'productoId' => $detail->productoId,
                    'cantidad' => $detail->cantidad,
                    'nameProd' => $detail->nameProd,
                    'precioBruto' => $detail->precioBruto,
                    'precioNeto' => $detail->precioNeto,
                ]);

            }

            $order->update(['estado' => 2]);
            $this->earnPosLoyaltyPoints($order, $store);

            return ['history' => $history->load('details'), 'idempotent' => false];
        });

        return response()->json([
            'data' => $result['history'],
            'idempotent' => $result['idempotent'],
            'message' => $result['idempotent'] ? 'La orden ya estaba pagada.' : 'Orden pagada exitosamente.',
        ]);
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'payment' => ['nullable', 'string', 'in:efectivo,tarjeta,transferencia'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $paymentTypeMap = ['efectivo' => 1, 'tarjeta' => 2, 'transferencia' => 3];

        $query = PosOrderHistory::where('creator', $store->createdby)
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('fecha', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('fecha', '<=', $to))
            ->when(
                $validated['payment'] ?? null,
                fn ($query, $payment) => $query->where('tipoPago', $paymentTypeMap[$payment]),
            );

        $stats = (clone $query)->selectRaw(
            'COUNT(*) as sales_count, COALESCE(SUM(total), 0) as total, '
            .'COALESCE(AVG(total), 0) as average, '
            .'SUM(CASE WHEN tipoPago = 1 THEN 1 ELSE 0 END) as cash, '
            .'SUM(CASE WHEN tipoPago = 2 THEN 1 ELSE 0 END) as card, '
            .'SUM(CASE WHEN tipoPago = 3 THEN 1 ELSE 0 END) as transfer'
        )->first();

        $orders = $query
            ->with('details')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            ...$orders->toArray(),
            'stats' => [
                'count' => (int) $stats->sales_count,
                'total' => (float) $stats->total,
                'average' => (float) $stats->average,
                'cash' => (int) $stats->cash,
                'card' => (int) $stats->card,
                'transfer' => (int) $stats->transfer,
            ],
        ]);
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
        if (! $client) {
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
            'idempotency_key' => 'required|string|max:100',
        ]);

        $config = LoyaltyConfig::getConfig($store->id);
        if (! $config->enabled) {
            return response()->json(['message' => 'Programa de lealtad no disponible.'], 422);
        }

        $client = Client::where('store_id', $store->id)->find($request->client_id);
        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }
        if ($request->points < $config->minimum_points_to_redeem) {
            return response()->json(['message' => "Minimo {$config->minimum_points_to_redeem} puntos para canjear."], 422);
        }
        if ($config->pesos_per_point <= 0) {
            return response()->json(['message' => 'Configuracion de lealtad invalida.'], 422);
        }
        if ($request->points % $config->pesos_per_point !== 0) {
            return response()->json(['message' => "Los puntos deben ser multiplo de {$config->pesos_per_point}."], 422);
        }

        $discount = floor($request->points / $config->pesos_per_point);
        $redeemed = LoyaltyPoint::redeemPoints(
            $store->id,
            $client->id,
            $request->points,
            'pos:'.$request->idempotency_key,
            'pos_redeem',
        );
        if (! $redeemed) {
            return response()->json(['message' => 'Puntos insuficientes.'], 422);
        }

        return response()->json(['data' => [
            'points_redeemed' => $request->points,
            'discount' => $discount,
            'remaining' => LoyaltyPoint::getBalance($store->id, $request->client_id),
        ]]);
    }

    private function redeemPosLoyalty(Store $store, PosOrder $order, int $points, float $maximumDiscount): float
    {
        if ($points === 0) {
            return 0;
        }
        if (! $order->telefono) {
            throw ValidationException::withMessages(['loyalty_points' => ['La orden no tiene telefono de cliente.']]);
        }

        $config = LoyaltyConfig::getConfig($store->id);
        $client = Client::where('store_id', $store->id)->where('phone', $order->telefono)->first();
        if (! $config->enabled || ! $client || $config->pesos_per_point <= 0) {
            throw ValidationException::withMessages(['loyalty_points' => ['No es posible canjear puntos para este cliente.']]);
        }
        if ($points < $config->minimum_points_to_redeem) {
            throw ValidationException::withMessages([
                'loyalty_points' => ["Minimo {$config->minimum_points_to_redeem} puntos para canjear."],
            ]);
        }
        if ($points % $config->pesos_per_point !== 0) {
            throw ValidationException::withMessages([
                'loyalty_points' => ["Los puntos deben ser multiplo de {$config->pesos_per_point}."],
            ]);
        }

        $discount = (float) floor($points / $config->pesos_per_point);
        if ($discount <= 0 || $discount > $maximumDiscount) {
            throw ValidationException::withMessages(['loyalty_points' => ['El canje excede el total de la orden.']]);
        }
        if (! LoyaltyPoint::redeemPoints($store->id, $client->id, $points, $order->noOrder, 'pos_redeem')) {
            throw ValidationException::withMessages(['loyalty_points' => ['Puntos insuficientes.']]);
        }

        return $discount;
    }

    private function earnPosLoyaltyPoints(PosOrder $order, Store $store): void
    {
        if (! $order->telefono) {
            return;
        }
        $config = LoyaltyConfig::getConfig($store->id);
        if (! $config->enabled || $config->points_per_peso <= 0) {
            return;
        }

        $client = Client::where('store_id', $store->id)->where('phone', $order->telefono)->first();
        if (! $client) {
            return;
        }

        $points = (int) floor($order->total * $config->points_per_peso);
        if ($points > 0) {
            LoyaltyPoint::addPoints(
                $store->id,
                $client->id,
                $points,
                'pos_earn',
                "Venta POS {$order->noOrder}",
                $order->noOrder,
            );
        }
    }
}

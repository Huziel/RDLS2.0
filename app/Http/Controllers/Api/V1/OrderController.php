<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\Checkout;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\ExtraCharge;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    // Customer checkout
    public function checkout(Request $request, $storeSerial)
    {
        $request->validate([
            'nombre' => ['required', 'string'],
            'telefono' => ['required', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'costo_envio' => ['nullable', 'numeric', 'min:0'],
            'direccion' => ['nullable', 'string', 'required_if:tipo_envio,shipping,national'],
            'ciudad' => ['nullable', 'string'],
            'codigo_postal' => ['nullable', 'string'],
            'loyalty_points' => ['prohibited'],
            'tipo_envio' => ['nullable', 'string', 'in:pickup,shipping,national'],
        ]);

        $cartId = $request->header('X-Cart-Token') ?? $request->session()->getId();
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey !== null && (strlen($idempotencyKey) > 100 || trim($idempotencyKey) === '')) {
            throw ValidationException::withMessages(['idempotency_key' => ['La clave de idempotencia no es valida.']]);
        }

        try {
            $order = app(Checkout::class)(
                $cartId,
                $storeSerial,
                $request->nombre,
                $request->telefono,
                $request->lat,
                $request->lng,
                $request->costo_envio,
                $request->only(['direccion', 'ciudad', 'codigo_postal']),
                0,
                $idempotencyKey,
                $request->tipo_envio,
            );

            if ($order->wasRecentlyCreated) {
                $this->notifyStoreOwner($order, $storeSerial);
                $this->trackSubscriptionSale($storeSerial, $order->total);
            }

            return response()->json([
                'data' => [
                    'order_id' => $order->order,
                    'id' => $order->id,
                    'total' => (float) $order->total,
                    'shipping' => (float) $order->totEnvio,
                    'loyalty_discount' => (float) $order->loyalty_discount,
                    'idempotent' => ! $order->wasRecentlyCreated,
                ],
                'message' => $order->wasRecentlyCreated ? 'Orden creada exitosamente.' : 'Orden recuperada.',
            ], $order->wasRecentlyCreated ? 201 : 200);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Online checkout failed', ['exception' => $exception]);

            return response()->json(['message' => 'No fue posible crear la orden.'], 500);
        }
    }

    // Store owner: list orders
    public function index(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $orders = PurchaseOrder::with(['cartItems.productData', 'shippingForm', 'shippingOrder.deliver'])
            ->where('serial', $store->serial)
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('order', 'like', "%{$request->search}%")
                    ->orWhere('nombre', 'like', "%{$request->search}%")
                    ->orWhere('tel', 'like', "%{$request->search}%");
            }))
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 20));

        $result = $orders->through(function ($order) {
            $cartStatuses = $order->cartItems->pluck('status')->unique()->toArray();
            $shipping = $order->shippingOrder;

            return [
                'id' => $order->id,
                'order' => $order->order,
                'cliente' => $order->nombre,
                'telefono' => $order->tel,
                'total' => (float) $order->total,
                'envio' => (float) $order->totEnvio,
                'fecha' => $order->date,
                'paid' => in_array('3', $cartStatuses),
                'lat' => $order->lat,
                'lng' => $order->long,
                'delivery_status' => $shipping ? [
                    'id' => $shipping->id,
                    'status' => $shipping->status,
                    'status_label' => ['0' => 'Pendiente repartidor', '1' => 'En camino', '2' => 'En proceso', '3' => 'Entregado'][$shipping->status] ?? $shipping->status,
                    'delivery_id' => $shipping->delivery,
                    'delivery_name' => $shipping->deliver->name ?? null,
                ] : null,
                'shipping' => $order->shippingForm ? [
                    'nombre' => $order->shippingForm->nombre,
                    'direccion' => $order->shippingForm->direccion,
                    'ciudad' => $order->shippingForm->ciudad,
                ] : null,
                'items_count' => $order->cartItems->count(),
                'items' => $order->cartItems->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->productData->keyy ?? '',
                    'image' => $i->productData->link ?? null,
                    'qty' => $i->cant,
                    'price' => (float) $i->price,
                ]),
            ];
        });

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $order = PurchaseOrder::with(['cartItems' => fn ($q) => $q->with('productData'), 'shippingForm', 'extraCharges'])
            ->where('serial', $store->serial)
            ->findOrFail($id);

        // Re-fetch items with proper product data and addons
        $items = Cart::where('orderC', $order->order)
            ->with(['productData', 'addons.addon'])
            ->get()
            ->map(fn ($i) => [
                'name' => $i->productData->keyy ?? 'Producto #'.$i->product,
                'image' => $i->productData->link ?? null,
                'qty' => $i->cant,
                'price' => (float) $i->price,
                'addons' => $i->addons->map(fn ($a) => [
                    'name' => $a->addon->nombre ?? '',
                    'price' => (float) ($a->addon->precio ?? 0),
                ]),
            ]);

        // Check for active shipping and verification code
        $shippingOrder = ShippingOrder::with('deliver')->where('ordenCompra', $order->id)->first();
        $verificationCode = null;
        if ($shippingOrder && in_array($shippingOrder->status, ['1', '2'])) {
            $verificationCode = VerificationCode::where('orderC', $order->order)->value('code');
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order' => $order->order,
                'cliente' => $order->nombre,
                'telefono' => $order->tel,
                'total' => (float) $order->total,
                'envio' => (float) $order->totEnvio,
                'fecha' => $order->date,
                'lat' => $order->lat,
                'lng' => $order->long,
                'items' => $items,
                'verification_code' => $verificationCode,
                'shipping_status' => $shippingOrder ? [
                    'status' => $shippingOrder->status,
                    'status_label' => ['0' => 'Pendiente', '1' => 'En camino', '2' => 'En proceso', '3' => 'Entregado'][$shippingOrder->status] ?? 'Desconocido',
                    'delivery_name' => $shippingOrder->deliver->name ?? null,
                ] : null,
                'shipping' => $order->shippingForm ? [
                    'nombre' => $order->shippingForm->nombre,
                    'direccion' => $order->shippingForm->direccion,
                    'ciudad' => $order->shippingForm->ciudad,
                    'codigo_postal' => $order->shippingForm->codigoPostal,
                    'pais' => $order->shippingForm->pais,
                ] : null,
                'extra_charges' => $order->extraCharges->map(fn ($e) => [
                    'precio' => (float) $e->precio,
                    'tipo' => $e->tipoCargo,
                ]),
            ],
        ]);
    }

    private function notifyStoreOwner($order, $storeSerial)
    {
        try {
            $store = Store::where('serial', $storeSerial)->first();
            if (! $store) {
                return;
            }

            $owner = User::where('name', $store->createdby)->first();
            if (! $owner) {
                return;
            }

            $subject = "Nueva venta - {$order->order}";
            $body = '
            <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px">
                <h2 style="color:#333">Nueva venta registrada</h2>
                <p>Se ha recibido un nuevo pedido en tu tienda:</p>
                <table style="width:100%;border-collapse:collapse;margin:16px 0">
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Orden:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$order->order.'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Cliente:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$order->nombre.'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Telefono:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$order->tel.'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Total:</strong></td><td style="padding:8px;border-bottom:1px solid #eee;color:#059669;font-weight:700">$'.number_format($order->total, 2).'</td></tr>
                    <tr><td style="padding:8px"><strong>Fecha:</strong></td><td style="padding:8px">'.$order->date.'</td></tr>
                </table>
                <p style="color:#666;font-size:14px">Revisa tu dashboard para mas detalles.</p>
            </div>';

            MailService::send($owner->name, $subject, $body);
        } catch (\Exception $e) {
            Log::error('Order notification failed: '.$e->getMessage());
        }
    }

    // Public: get order detail for thank-you page
    public function publicOrderDetail(Request $request, $id)
    {
        $cartToken = $request->header('X-Cart-Token');
        if (! is_string($cartToken) || trim($cartToken) === '') {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }

        $order = is_numeric($id)
            ? PurchaseOrder::with('shippingForm')->where('session', $cartToken)->find($id)
            : PurchaseOrder::with('shippingForm')->where('session', $cartToken)->where('order', $id)->first();

        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }

        $items = Cart::where('orderC', $order->order)
            ->with(['productData', 'addons.addon'])
            ->get()
            ->map(fn ($i) => [
                'name' => $i->productData->keyy ?? 'Producto #'.$i->product,
                'image' => $i->productData->link ?? null,
                'qty' => (int) $i->cant,
                'price' => (float) $i->price,
            ]);

        return response()->json(['data' => [
            'id' => $order->id,
            'order' => $order->order,
            'cliente' => $order->nombre,
            'telefono' => $order->tel,
            'total' => (float) $order->total,
            'envio' => (float) $order->totEnvio,
            'fecha' => $order->date,
            'items' => $items,
        ]]);
    }

    // Store owner: add extra charge
    public function addExtraCharge(Request $request, $id)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();
        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);
        $request->validate(['precio' => 'required|numeric|min:0', 'tipo' => 'required|string']);
        ExtraCharge::create(['orderP' => $order->order, 'precio' => $request->precio, 'tipoCargo' => $request->tipo]);

        return response()->json(['message' => 'Cargo extra agregado.']);
    }

    // Store owner: confirm payment
    public function confirmPayment(Request $request, $id)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = DB::transaction(function () use ($store, $id) {
            $order = PurchaseOrder::where('serial', $store->serial)->lockForUpdate()->findOrFail($id);
            Cart::where('orderC', $order->order)
                ->where('variation', $store->serial)
                ->where('status', '!=', '3')
                ->update(['status' => '3']);

            return $order;
        });

        return response()->json(['message' => 'Pago confirmado.', 'data' => ['order' => $order->order, 'status' => '3']]);
    }

    private function trackSubscriptionSale($storeSerial, $amount)
    {
        try {
            $store = Store::where('serial', $storeSerial)->first();
            if (! $store) {
                return;
            }
            $sub = StoreSubscription::getActive($store->id);
            if ($sub && $sub->plan->price_percent > 0) {
                $sub->addSale($amount);
            }
        } catch (\Exception $e) {
        }
    }

    // Customer order history
    public function myOrders(Request $request)
    {
        $userId = $request->header('X-Cart-Token') ?? $request->session()->getId();

        $cartOrders = Cart::where('user', $userId)
            ->whereNotNull('orderC')
            ->whereIn('status', ['2', '3', '4', '5', '6', '7', '8'])
            ->pluck('orderC')
            ->unique();

        $orders = PurchaseOrder::with(['cartItems.productData:id,keyy,number,link'])
            ->whereIn('order', $cartOrders)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'order' => $o->order,
                'total' => (float) $o->total,
                'fecha' => $o->date,
                'items' => $o->cartItems->map(fn ($i) => [
                    'name' => $i->productData->keyy ?? '',
                    'qty' => $i->cant,
                ]),
            ]);

        return response()->json(['data' => $orders]);
    }
}

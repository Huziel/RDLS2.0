<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\Checkout;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Client;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Services\MailService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    // Customer checkout
    public function checkout(Request $request, $storeSerial)
    {
        $request->validate([
            'nombre' => ['required', 'string'],
            'telefono' => ['required', 'string'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'costo_envio' => ['nullable', 'numeric', 'min:0'],
            'direccion' => ['nullable', 'string'],
            'ciudad' => ['nullable', 'string'],
            'codigo_postal' => ['nullable', 'string'],
        ]);

        $cartId = $request->header('X-Cart-Token') ?? $request->session()->getId();

        try {
            $order = app(Checkout::class)(
                $cartId,
                $storeSerial,
                $request->nombre,
                $request->telefono,
                $request->lat,
                $request->lng,
                $request->costo_envio,
                $request->only(['direccion', 'ciudad', 'codigo_postal'])
            );

            // Notify store owner via email
            $this->notifyStoreOwner($order, $storeSerial);

            // Auto-earn loyalty points for the customer
            $this->earnLoyaltyPoints($order, $storeSerial, $request->telefono);

            // Track sale for subscription
            $this->trackSubscriptionSale($storeSerial, $order->total);

            return response()->json([
                'data' => [
                    'order_id' => $order->order,
                    'id' => $order->id,
                    'total' => (float) $order->total,
                    'shipping' => (float) $order->totEnvio,
                ],
                'message' => 'Orden creada exitosamente.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // Store owner: list orders
    public function index(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $orders = PurchaseOrder::with(['cartItems.productData', 'shippingForm', 'shippingOrder.deliver'])
            ->where('serial', $store->serial)
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
                    'status_label' => ['0'=>'Pendiente repartidor','1'=>'En camino','2'=>'En proceso','3'=>'Entregado'][$shipping->status] ?? $shipping->status,
                    'delivery_id' => $shipping->delivery,
                    'delivery_name' => $shipping->deliver->name ?? null,
                ] : null,
                'shipping' => $order->shippingForm ? [
                    'nombre' => $order->shippingForm->nombre,
                    'direccion' => $order->shippingForm->direccion,
                    'ciudad' => $order->shippingForm->ciudad,
                ] : null,
                'items_count' => $order->cartItems->count(),
                'items' => $order->cartItems->map(fn($i) => [
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

    public function show($id)
    {
        $order = PurchaseOrder::with(['cartItems' => fn($q) => $q->with('productData'), 'shippingForm', 'extraCharges'])
            ->findOrFail($id);

        // Re-fetch items with proper product data and addons
        $items = \App\Models\Cart::where('orderC', $order->order)
            ->with(['productData', 'addons.addon'])
            ->get()
            ->map(fn($i) => [
                'name' => $i->productData->keyy ?? 'Producto #' . $i->product,
                'image' => $i->productData->link ?? null,
                'qty' => $i->cant,
                'price' => (float) $i->price,
                'addons' => $i->addons->map(fn($a) => [
                    'name' => $a->addon->nombre ?? '',
                    'price' => (float) ($a->addon->precio ?? 0),
                ]),
            ]);

        // Check for active shipping and verification code
        $shippingOrder = \App\Models\ShippingOrder::with('deliver')->where('ordenCompra', $order->id)->first();
        $verificationCode = null;
        if ($shippingOrder && in_array($shippingOrder->status, ['1', '2'])) {
            $verificationCode = \App\Models\VerificationCode::where('orderC', $order->order)->value('code');
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
                    'status_label' => ['0'=>'Pendiente','1'=>'En camino','2'=>'En proceso','3'=>'Entregado'][$shippingOrder->status] ?? 'Desconocido',
                    'delivery_name' => $shippingOrder->deliver->name ?? null,
                ] : null,
                'shipping' => $order->shippingForm ? [
                    'nombre' => $order->shippingForm->nombre,
                    'direccion' => $order->shippingForm->direccion,
                    'ciudad' => $order->shippingForm->ciudad,
                ] : null,
                'extra_charges' => $order->extraCharges->map(fn($e) => [
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
            if (!$store) return;

            $owner = User::where('name', $store->createdby)->first();
            if (!$owner) return;

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
            \Illuminate\Support\Facades\Log::error('Order notification failed: ' . $e->getMessage());
        }
    }

    // Public: get order detail for thank-you page
    public function publicOrderDetail(Request $request, $id)
    {
        $order = is_numeric($id)
            ? PurchaseOrder::with('shippingForm')->find($id)
            : PurchaseOrder::with('shippingForm')->where('order', $id)->first();

        if (!$order) {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }

        $items = \App\Models\Cart::where('orderC', $order->order)
            ->with(['productData', 'addons.addon'])
            ->get()
            ->map(fn($i) => [
                'name' => $i->productData->keyy ?? 'Producto #' . $i->product,
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

    // Store owner: confirm payment
    public function confirmPayment(Request $request, $id)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);

        \App\Models\Cart::where('orderC', $order->order)->update(['status' => '3']);

        return response()->json(['message' => 'Pago confirmado.', 'data' => ['order' => $order->order, 'status' => '3']]);
    }

    private function earnLoyaltyPoints($order, $storeSerial, $phone)
    {
        try {
            $store = Store::where('serial', $storeSerial)->first();
            if (!$store) return;
            $config = LoyaltyConfig::getConfig($store->id);
            if (!$config->enabled) return;

            $client = Client::where('store_id', $store->id)->where('phone', $phone)->first();
            if (!$client) return;

            $points = (int) floor($order->total * $config->points_per_peso);
            if ($points > 0) {
                LoyaltyPoint::addPoints($store->id, $client->id, $points, 'earn', "Compra {$order->order}", $order->order);
            }
        } catch (\Exception $e) {}
    }

    private function trackSubscriptionSale($storeSerial, $amount)
    {
        try {
            $store = Store::where('serial', $storeSerial)->first();
            if (!$store) return;
            $sub = StoreSubscription::getActive($store->id);
            if ($sub && $sub->plan->price_percent > 0) {
                $sub->addSale($amount);
            }
        } catch (\Exception $e) {}
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
            ->map(fn($o) => [
                'id' => $o->id,
                'order' => $o->order,
                'total' => (float) $o->total,
                'fecha' => $o->date,
                'items' => $o->cartItems->map(fn($i) => [
                    'name' => $i->product->keyy ?? '',
                    'qty' => $i->cant,
                ]),
            ]);

        return response()->json(['data' => $orders]);
    }
}

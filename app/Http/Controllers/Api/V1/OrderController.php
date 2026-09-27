<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MercadoPago\CreatePreference;
use App\Actions\Order\CancelOrder;
use App\Actions\Order\Checkout;
use App\Actions\Order\ConfirmManualPayment;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\PaymentPreferenceNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CheckoutRequest;
use App\Http\Requests\Order\ConfirmPaymentRequest;
use App\Http\Resources\PublicOrderProjection;
use App\Http\Resources\PublicOrderTicket;
use App\Models\Cart;
use App\Models\ExtraCharge;
use App\Models\MercadoPagoAccount;
use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\User;
use App\Services\CanonicalOrderAmount;
use App\Services\MailService;
use App\Services\OrderIdempotency;
use App\Services\OrderNotificationHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OrderController extends Controller
{
    // Customer checkout
    public function checkout(CheckoutRequest $request, $storeSerial, OrderIdempotency $idempotency)
    {
        $cartId = $request->header('X-Cart-Token') ?? $request->session()->getId();
        $idempotencyKey = $idempotency->key($request);
        $requestHash = $idempotency->hash($request->validated());

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
                $request->payment_method,
                $requestHash,
                $request->header('X-Store-Capability'),
            );

            if ($order->wasRecentlyCreated) {
                $this->notifyStoreOwner($order, $order->serial);
                $this->trackSubscriptionSale($order->serial, $order->total);
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
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (HttpExceptionInterface $exception) {
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

        $orders = PurchaseOrder::with(['cartItems.productData', 'shippingForm', 'shippingOrder.deliver', 'payment', 'returnRecord'])
            ->where('serial', $store->serial)
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('order', 'like', "%{$request->search}%")
                    ->orWhere('nombre', 'like', "%{$request->search}%")
                    ->orWhere('tel', 'like', "%{$request->search}%");
            }))
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 20));

        $result = $orders->through(function ($order) {
            $shipping = $order->shippingOrder;

            return [
                'id' => $order->id,
                'order' => $order->order,
                'cliente' => $order->nombre,
                'telefono' => $order->tel,
                'total' => (float) $order->total,
                'envio' => (float) $order->totEnvio,
                'fecha' => $order->date,
                // FASE 6B P0-2: estado canonico (order_state + cart status='3'),
                // no el cart status como unica fuente.
                'paid' => $order->isPaid(),
                'payment' => $this->safePaymentSummary($order->payment),
                'return' => $order->returnRecord ? ['status' => $order->returnRecord->status] : null,
                'lat' => $order->lat,
                'lng' => $order->long,
                'delivery_status' => $shipping ? [
                    'id' => $shipping->id,
                    'status' => $shipping->status,
                    'status_label' => ['0' => 'Pendiente repartidor', '1' => 'En camino', '2' => 'En proceso', '3' => 'Entregado', '4' => 'Cancelado'][$shipping->status] ?? $shipping->status,
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
        $order = PurchaseOrder::with(['cartItems' => fn ($q) => $q->with('productData'), 'shippingForm', 'extraCharges', 'payment', 'returnRecord'])
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

        // Check for active shipping without exposing delivery verification secrets.
        $shippingOrder = ShippingOrder::with('deliver')->where('ordenCompra', $order->id)->first();

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order' => $order->order,
                'cliente' => $order->nombre,
                'telefono' => $order->tel,
                'total' => (float) $order->total,
                'envio' => (float) $order->totEnvio,
                'payment' => $this->safePaymentSummary($order->payment),
                'return' => $order->returnRecord ? ['status' => $order->returnRecord->status] : null,
                'fecha' => $order->date,
                'lat' => $order->lat,
                'lng' => $order->long,
                'items' => $items,
                'shipping_status' => $shippingOrder ? [
                    'status' => $shippingOrder->status,
                    'status_label' => ['0' => 'Pendiente', '1' => 'En camino', '2' => 'En proceso', '3' => 'Entregado', '4' => 'Cancelado'][$shippingOrder->status] ?? 'Desconocido',
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

    private function notifyStoreOwner(PurchaseOrder $order, string $storeSerial): void
    {
        try {
            $store = Store::whereRaw('LOWER(serial) = ?', [mb_strtolower($storeSerial)])->first();
            if (! $store) {
                return;
            }

            $owner = User::where('name', $store->createdby)->first();
            if (! $owner) {
                return;
            }

            $notification = app(OrderNotificationHtml::class);
            MailService::send($owner->name, $notification->subject($order), $notification->body($order));
        } catch (\Exception $e) {
            Log::error('Order notification failed: '.$e->getMessage());
        }
    }

    // Public: get order detail for thank-you page
    public function publicOrderDetail(Request $request, string $storeSerial, string $order)
    {
        $found = $this->publicOrder($this->requireCartToken($request), $storeSerial, $order);
        if (! $found) {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }
        $found->load(['payment', 'cartItems.productData', 'cartItems.addons.addon']);

        return response()->json(['data' => PublicOrderTicket::make($found)->resolve($request)]);
    }

    // Public: request a MercadoPago preference for the customer's own order
    public function publicPaymentPreference(Request $request, $storeSerial, $orderReference)
    {
        $cartToken = $this->requireCartToken($request);
        $order = $this->publicOrder($cartToken, $storeSerial, $orderReference);
        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }
        if ($order->isCancelled()) {
            return response()->json(['message' => 'La orden fue cancelada.'], 422);
        }
        if ($order->isPaid()) {
            return response()->json(['message' => 'La orden ya fue pagada.'], 422);
        }
        // D2: una excepcion de cobro no es fondo cobrado, pero tampoco se
        // reabre el pago por el cliente: lo resuelve finanzas.
        if ($order->payment?->status === 'payment_exception') {
            return response()->json([
                'message' => 'La orden tiene una excepcion de cobro. Contacta a la tienda.',
            ], 422);
        }
        // El cobro fue creado por otro flujo (cash, transferencia manual, etc.):
        // no llamar a MercadoPago por ellas ni exponer 502 con un error de regla.
        if (! $order->payment || $order->payment->method !== 'mercado_pago') {
            return response()->json(['message' => 'La orden no fue creada para MercadoPago.'], 422);
        }

        $store = $order->store;
        $account = $store ? MercadoPagoAccount::where('idLog', $store->owner?->id)->first() : null;
        if (! $account || ! $account->merchantId) {
            return response()->json(['message' => 'Esta tienda debe configurar su cuenta de MercadoPago.'], 422);
        }

        try {
            $preference = app(CreatePreference::class)(
                $order,
                $account,
                $store,
                app(OrderIdempotency::class)->key($request),
            );

            return response()->json([
                'data' => [
                    'order_id' => $order->order,
                    'preference_id' => $preference['preference_id'],
                    'init_point' => $preference['init_point'],
                    'sandbox_init_point' => $preference['sandbox_init_point'],
                ],
                'message' => 'Preferencia de pago creada.',
            ]);
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ValidationException $exception) {
            // La cabecera Idempotency-Key faltante o invalida es un error del
            // cliente: 422 accionable, jamas 502 por una cabecera ausente.
            return response()->json([
                'message' => $exception->validator->errors()->first(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (PaymentPreferenceNotAllowed $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::warning('Public MercadoPago preference failed.', [
                'order' => $order->order,
                'exception' => $exception,
            ]);

            return response()->json(['message' => 'No fue posible crear la preferencia de pago.'], 502);
        }
    }

    // Public: poll payment/order status after a MercadoPago redirect
    public function publicPaymentStatus(Request $request, $storeSerial, $orderReference)
    {
        $cartToken = $this->requireCartToken($request);
        $order = $this->publicOrder($cartToken, $storeSerial, $orderReference);
        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }

        return response()->json(['data' => PublicOrderProjection::make($order)->resolve($request)]);
    }

    // Public: customer cancels her own pending order (stock is restored once)
    public function publicCancel(Request $request, $storeSerial, $orderReference)
    {
        $cartToken = $this->requireCartToken($request);
        $order = $this->publicOrder($cartToken, $storeSerial, $orderReference);
        if (! $order) {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }
        // FASE 6B P0-2 + D2: una orden pagada o con excepcion de cobro
        // nunca se cancela publicamente.
        if ($order->blocksCustomerCancellation()) {
            return response()->json([
                'message' => 'Una orden pagada o con excepcion de cobro no puede cancelarse publicamente.',
            ], 422);
        }

        try {
            $result = app(CancelOrder::class)(
                $order,
                null,
                'customer',
                app(OrderIdempotency::class)->key($request),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->validator->errors()->first(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json([
            'data' => $result,
            'message' => match (true) {
                // Mensaje dependiente del resultado real: solo hubo restock si
                // la entrega no habia salido; si no, se requiere retorno fisico.
                $result['restocked'] === true => 'Orden cancelada. Se repuso el inventario.',
                ($result['return_pending'] ?? false) === true => 'Orden cancelada. La entrega ya habia salido; se requiere retorno fisico.',
                default => 'Orden cancelada.',
            },
        ]);
    }

    // Store owner: cancel any own order (paid orders flag a manual refund)
    public function cancel(Request $request, $id)
    {
        $this->denySuperAdminMutation($request);
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);
        try {
            $result = app(CancelOrder::class)(
                $order,
                $user->id,
                'store_owner',
                app(OrderIdempotency::class)->key($request),
            );
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json([
            'data' => $result,
            'message' => $result['idempotent'] ? 'La orden ya estaba cancelada.' : 'Orden cancelada.',
        ]);
    }

    private function requireCartToken(Request $request): string
    {
        $cartToken = $request->header('X-Cart-Token');
        if (! is_string($cartToken) || trim($cartToken) === '') {
            abort(404, 'Orden no encontrada.');
        }

        return $cartToken;
    }

    private function publicOrder(string $cartToken, string $storeSerial, string $reference): ?PurchaseOrder
    {
        $query = PurchaseOrder::with('payment')
            ->where('session', $cartToken)
            ->whereRaw('LOWER(serial) = ?', [mb_strtolower($storeSerial)]);
        $order = is_numeric($reference)
            ? $query->find($reference)
            : $query->where('order', $reference)->first();

        /** @var PurchaseOrder|null $order */
        return $order;
    }

    // Store owner: add extra charge
    public function addExtraCharge(Request $request, $id, OrderIdempotency $idempotency, CanonicalOrderAmount $amounts)
    {
        $this->denySuperAdminMutation($request);
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();
        $request->validate([
            'precio' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'tipo' => ['required', 'string'],
        ]);
        $key = $idempotency->key($request);
        $hash = $idempotency->hash($request->only(['precio', 'tipo']));

        try {
            $result = DB::transaction(function () use ($store, $user, $id, $request, $idempotency, $amounts, $key, $hash) {
                $order = PurchaseOrder::where('serial', $store->serial)->lockForUpdate()->findOrFail($id);
                $payment = $order->payment()->lockForUpdate()->firstOrFail();
                $eventKey = $idempotency->eventKey($order, 'extra-charge', $key);
                if ($event = $idempotency->find($eventKey, $hash)) {
                    return array_merge($event->response_data, ['idempotent' => true]);
                }
                if ($payment->frozen_at !== null) {
                    return ['error' => 'El importe ya esta congelado.', 'status_code' => 409];
                }
                $amounts->calculate($order, true);
                ExtraCharge::create(['orderP' => $order->order, 'precio' => $request->precio, 'tipoCargo' => $request->tipo]);
                $amounts->snapshot($order, $payment, false, true);
                $response = ['order' => $order->order, 'amount_due' => $payment->fresh()->amount_due];
                $idempotency->record($order, $store->id, $user->id, 'store_owner', 'extra_charge_added', $eventKey, $hash, null, ['amount_due' => $response['amount_due']], 200, $response);

                return $response + ['idempotent' => false];
            });
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status_code']);
        }

        return response()->json(['data' => $result, 'message' => 'Cargo extra agregado.']);
    }

    // Store owner: confirm payment
    public function confirmPayment(ConfirmPaymentRequest $request, $id, ConfirmManualPayment $confirm, OrderIdempotency $idempotency)
    {
        $this->denySuperAdminMutation($request);
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);
        try {
            $result = $confirm($order, $store->id, $user->id, $idempotency->key($request), $request->validated());
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status_code']);
        }

        return response()->json([
            'message' => $result['idempotent'] ? 'La orden ya estaba pagada.' : 'Pago confirmado.',
            'data' => ['order' => $result['order'], 'status' => '3', 'idempotent' => $result['idempotent']],
        ]);
    }

    private function safePaymentSummary(?OrderPayment $payment): ?array
    {
        if (! $payment) {
            return null;
        }

        return [
            'method' => $payment->method,
            'terms' => $payment->terms,
            'status' => $payment->status,
            'currency' => $payment->currency,
            'amount_due' => (string) $payment->amount_due,
            'amount_paid' => (string) $payment->amount_paid,
            'amount_refunded' => (string) $payment->amount_refunded,
        ];
    }

    private function denySuperAdminMutation(Request $request): void
    {
        abort_if($request->user()->hasRole('super-admin'), 403, 'Superadmin es solo lectura para finanzas de ordenes.');
    }

    private function trackSubscriptionSale($storeSerial, $amount)
    {
        try {
            $store = Store::whereRaw('LOWER(serial) = ?', [mb_strtolower($storeSerial)])->first();
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
        return response()->json(['message' => 'El historial requiere un flujo de claim seguro.'], 503);
    }
}

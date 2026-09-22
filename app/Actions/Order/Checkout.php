<?php

namespace App\Actions\Order;

use App\Actions\Inventory\ConsumeInventory;
use App\Models\Cart;
use App\Models\CartAddon;
use App\Models\Client;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Models\ProductAddon;
use App\Models\PurchaseOrder;
use App\Models\ShippingForm;
use App\Models\Store;
use App\Models\StoreFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Checkout
{
    public function __construct(private readonly ConsumeInventory $consumeInventory) {}

    public function __invoke(
        string $sessionId,
        string $storeSerial,
        string $customerName,
        string $phone,
        ?float $lat,
        ?float $lng,
        ?float $requestedShippingCost,
        array $shippingAddress = [],
        int $loyaltyPoints = 0,
        ?string $idempotencyKey = null,
        ?string $deliveryType = null,
    ): PurchaseOrder {
        $store = Store::where('serial', $storeSerial)->firstOrFail();

        return DB::transaction(function () use (
            $sessionId,
            $store,
            $customerName,
            $phone,
            $lat,
            $lng,
            $requestedShippingCost,
            $shippingAddress,
            $loyaltyPoints,
            $idempotencyKey,
            $deliveryType,
        ) {
            $cartItems = Cart::active()
                ->byUser($sessionId)
                ->byStore($store->serial)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                $existing = $idempotencyKey === null
                    ? null
                    : PurchaseOrder::where('serial', $store->serial)
                        ->where('session', $sessionId)
                        ->where('checkout_key', $this->checkoutKey($store->serial, $sessionId, $idempotencyKey))
                        ->first();

                if ($existing) {
                    $existing->wasRecentlyCreated = false;

                    return $existing;
                }

                throw ValidationException::withMessages(['cart' => ['El carrito esta vacio.']]);
            }

            $checkoutKey = $this->checkoutKey(
                $store->serial,
                $sessionId,
                $idempotencyKey ?? $cartItems->pluck('id')->implode(','),
            );
            $existing = PurchaseOrder::where('serial', $store->serial)
                ->where('session', $sessionId)
                ->where('checkout_key', $checkoutKey)
                ->first();
            if ($existing) {
                $originalItemIds = Cart::where('orderC', $existing->order)->orderBy('id')->pluck('id')->all();
                if ($originalItemIds !== $cartItems->pluck('id')->all()) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['La clave de idempotencia ya fue utilizada para otro carrito.'],
                    ]);
                }
                $existing->wasRecentlyCreated = false;

                return $existing;
            }

            if ($cartItems->contains(fn (Cart $item) => $item->dom !== $store->createdby)) {
                throw ValidationException::withMessages(['cart' => ['El carrito contiene productos de otra tienda.']]);
            }

            $quantities = $cartItems->groupBy('product')
                ->map(fn ($items) => (float) $items->sum('cant'));
            ($this->consumeInventory)($store->createdby, $quantities);

            $cartAddons = CartAddon::whereIn('noOrder', $cartItems->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $addons = ProductAddon::whereIn('id', $cartAddons->pluck('idAditivo'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $cartProducts = $cartItems->pluck('product', 'id');
            if ($cartAddons->contains(function (CartAddon $cartAddon) use ($addons, $cartProducts) {
                $addon = $addons->get($cartAddon->idAditivo);

                return ! $addon
                    || ! $addon->activo
                    || (int) $addon->idProd !== (int) $cartProducts->get($cartAddon->noOrder);
            })) {
                throw ValidationException::withMessages([
                    'cart' => ['El carrito contiene extras que ya no estan disponibles.'],
                ]);
            }

            $subtotal = (float) $cartItems->sum('price');
            $loyaltyDiscount = $this->redeemLoyalty(
                $store,
                $phone,
                $loyaltyPoints,
                $subtotal,
                "checkout:{$checkoutKey}",
            );
            $total = max(0, $subtotal - $loyaltyDiscount);
            $shippingCost = $this->shippingCost(
                $store,
                $deliveryType,
                $lat,
                $lng,
                $requestedShippingCost,
                $shippingAddress,
            );
            $orderNumber = 'ORD-'.now()->format('YmdHisv').'-'.random_int(100000, 999999);

            $order = PurchaseOrder::create([
                'order' => $orderNumber,
                'tel' => $phone,
                'serial' => $store->serial,
                'session' => $sessionId,
                'lat' => $lat ?? '0',
                'long' => $lng ?? '0',
                'total' => $total,
                'totEnvio' => $shippingCost,
                'nombre' => $customerName,
                'date' => now()->format('Y-m-d'),
                'checkout_key' => $checkoutKey,
                'loyalty_discount' => $loyaltyDiscount,
                'order_state' => PurchaseOrder::STATE_PENDING,
            ]);

            if (! empty($shippingAddress['direccion'])) {
                ShippingForm::create([
                    'noOrder' => $orderNumber,
                    'nombre' => $customerName,
                    'direccion' => $shippingAddress['direccion'] ?? '',
                    'ciudad' => $shippingAddress['ciudad'] ?? '',
                    'pais' => $shippingAddress['pais'] ?? '',
                    'codigoPostal' => $shippingAddress['codigo_postal'] ?? '',
                    'tipoEnvio' => $deliveryType ?? 'shipping',
                ]);
            }

            Cart::whereIn('id', $cartItems->pluck('id'))->update([
                'orderC' => $orderNumber,
                'status' => '2',
            ]);

            $this->earnLoyalty($store, $phone, $order);

            return $order;
        });
    }

    private function redeemLoyalty(Store $store, string $phone, int $points, float $subtotal, string $reference): float
    {
        if ($points === 0) {
            return 0;
        }

        $config = LoyaltyConfig::getConfig($store->id);
        $client = Client::where('store_id', $store->id)->where('phone', $phone)->first();
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
        if ($discount <= 0 || $discount > $subtotal) {
            throw ValidationException::withMessages(['loyalty_points' => ['El canje excede el total del carrito.']]);
        }
        if (! LoyaltyPoint::redeemPoints($store->id, $client->id, $points, $reference, 'checkout_redeem')) {
            throw ValidationException::withMessages(['loyalty_points' => ['Puntos insuficientes.']]);
        }

        return $discount;
    }

    private function checkoutKey(string $storeSerial, string $sessionId, string $key): string
    {
        return hash('sha256', implode('|', [$storeSerial, $sessionId, $key]));
    }

    private function earnLoyalty(Store $store, string $phone, PurchaseOrder $order): void
    {
        $config = LoyaltyConfig::getConfig($store->id);
        if (! $config->enabled || $config->points_per_peso <= 0) {
            return;
        }

        $client = Client::where('store_id', $store->id)->where('phone', $phone)->first();
        $points = (int) floor((float) $order->total * $config->points_per_peso);
        if ($client && $points > 0) {
            LoyaltyPoint::addPoints(
                $store->id,
                $client->id,
                $points,
                'checkout_earn',
                "Compra {$order->order}",
                $order->order,
            );
        }
    }

    private function shippingCost(
        Store $store,
        ?string $deliveryType,
        ?float $lat,
        ?float $lng,
        ?float $requestedCost,
        array $address,
    ): float {
        $deliveryType ??= empty($address['direccion'])
            ? 'pickup'
            : (($requestedCost ?? 0) == 0 ? 'national' : 'shipping');

        if ($deliveryType === 'pickup') {
            if (! $this->deliveryFeatureEnabled($store, 2, true)) {
                throw ValidationException::withMessages(['tipo_envio' => ['La recoleccion en tienda no esta disponible.']]);
            }

            return 0;
        }

        if ($deliveryType === 'national') {
            if (! $this->deliveryFeatureEnabled($store, 3, false)) {
                throw ValidationException::withMessages(['tipo_envio' => ['El envio nacional no esta disponible.']]);
            }

            return 0;
        }

        if (! $this->deliveryFeatureEnabled($store, 1, true)) {
            throw ValidationException::withMessages(['tipo_envio' => ['El envio local no esta disponible.']]);
        }

        $parts = explode('|', (string) $store->color);
        $defaults = [10, 4, 10];
        $rates = array_map(
            fn ($value, $index) => is_numeric($value) && $value !== '' ? max(0, (float) $value) : $defaults[$index],
            array_slice(array_pad($parts, 3, null), 0, 3),
            array_keys($defaults),
        );

        // Customer coordinates are not trusted proof of an address. Charge the highest configured tier.
        return max($rates);
    }

    private function deliveryFeatureEnabled(Store $store, int $component, bool $default): bool
    {
        $features = StoreFeature::where('idTienda', $store->id)->get(['idComponent', 'active']);
        if ($features->isEmpty()) {
            return $default;
        }

        return $features->contains(
            fn (StoreFeature $feature) => (int) $feature->idComponent === $component && (int) $feature->active === 1
        );
    }
}

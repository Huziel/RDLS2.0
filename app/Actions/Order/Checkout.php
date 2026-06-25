<?php

namespace App\Actions\Order;

use App\Models\Cart;
use App\Models\PurchaseOrder;
use App\Models\ShippingForm;
use Illuminate\Support\Facades\DB;

class Checkout
{
    public function __invoke(string $sessionId, string $storeSerial, string $customerName, string $phone, ?float $lat, ?float $lng, ?float $shippingCost, array $shippingAddress = []): PurchaseOrder
    {
        return DB::transaction(function () use ($sessionId, $storeSerial, $customerName, $phone, $lat, $lng, $shippingCost, $shippingAddress) {
            $cartItems = Cart::active()->byUser($sessionId)->byStore($storeSerial)->get();

            if ($cartItems->isEmpty()) {
                throw new \Exception('El carrito esta vacio.');
            }

            $total = $cartItems->sum('price');
            $orderId = 'ORD-' . now()->format('YmdHis') . '-' . rand(100, 999);
            $shippingCost = $shippingCost ?? 0;

            $order = PurchaseOrder::create([
                'order' => $orderId,
                'tel' => $phone,
                'serial' => $storeSerial,
                'session' => $sessionId,
                'lat' => $lat ?? '0',
                'long' => $lng ?? '0',
                'total' => $total,
                'totEnvio' => $shippingCost,
                'nombre' => $customerName,
                'date' => now()->format('Y-m-d'),
            ]);

            // Save shipping form if address provided
            if (!empty($shippingAddress['direccion'])) {
                ShippingForm::create([
                    'noOrder' => $orderId,
                    'nombre' => $customerName,
                    'direccion' => $shippingAddress['direccion'] ?? '',
                    'ciudad' => $shippingAddress['ciudad'] ?? '',
                    'pais' => $shippingAddress['pais'] ?? '',
                    'codigoPostal' => $shippingAddress['codigo_postal'] ?? '',
                    'tipoEnvio' => 'shipping',
                ]);
            }

            // Update cart items to status 2 (pre-order)
            Cart::active()->byUser($sessionId)->byStore($storeSerial)->update([
                'orderC' => $orderId,
                'status' => '2',
            ]);

            return $order;
        });
    }
}

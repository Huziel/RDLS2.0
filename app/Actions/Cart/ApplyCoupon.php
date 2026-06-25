<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\Coupon;
use Illuminate\Support\Facades\DB;

class ApplyCoupon
{
    public function __invoke(string $code, string $sessionId, string $storeSerial): array
    {
        return DB::transaction(function () use ($code, $sessionId, $storeSerial) {
            $coupon = Coupon::with('products')->where('codeC', $code)->firstOrFail();
            $store = \App\Models\Store::where('serial', $storeSerial)->firstOrFail();

            if ($coupon->idTienda != $store->id) {
                throw new \Exception('Este cupón no pertenece a esta tienda.');
            }

            if (! $coupon->isValid()) {
                throw new \Exception('El cupón expiró o no está vigente.');
            }

            $cartItems = Cart::active()->byUser($sessionId)->byStore($storeSerial)->get();

            if ($cartItems->isEmpty()) {
                throw new \Exception('No hay productos en el carrito.');
            }

            $total = $cartItems->sum('price');

            switch ($coupon->tipo) {
                case '1': // General discount
                    if ($coupon->cant > 0 && $coupon->cant < $total) {
                        $discountPerItem = $coupon->cant / $cartItems->count();
                    } elseif ($coupon->porcent > 0) {
                        $discountTotal = ($total * $coupon->porcent) / 100;
                        $discountPerItem = $discountTotal / $cartItems->count();
                    } else {
                        throw new \Exception('Error en la configuración del cupón.');
                    }

                    foreach ($cartItems as $item) {
                        $newPrice = max(0, $item->price - $discountPerItem);
                        $item->update(['price' => number_format($newPrice, 2, '.', '')]);
                    }
                    break;

                case '2': // Specific products
                    $couponProducts = $coupon->products->pluck('idData')->toArray();
                    $found = false;
                    foreach ($cartItems as $item) {
                        if (in_array($item->product, $couponProducts)) {
                            $cp = $coupon->products->firstWhere('idData', $item->product);
                            $discount = ($item->price * $cp->porcent) / 100;
                            $item->update(['price' => number_format(max(0, $item->price - $discount), 2, '.', '')]);
                            $found = true;
                        }
                    }
                    if (! $found) {
                        throw new \Exception('No hay productos en promoción en tu carrito.');
                    }
                    break;

                case '3': // Minimum purchase
                    if ($coupon->valorCompra > $total) {
                        throw new \Exception("Debes cubrir un mínimo de compra de \${$coupon->valorCompra} para usar este cupón.");
                    }
                    if ($coupon->cant > 0 && $coupon->cant < $total) {
                        $discountPerItem = $coupon->cant / $cartItems->count();
                    } elseif ($coupon->porcent > 0) {
                        $discountTotal = ($total * $coupon->porcent) / 100;
                        $discountPerItem = $discountTotal / $cartItems->count();
                    } else {
                        throw new \Exception('Error en la configuración del cupón.');
                    }
                    foreach ($cartItems as $item) {
                        $newPrice = max(0, $item->price - $discountPerItem);
                        $item->update(['price' => number_format($newPrice, 2, '.', '')]);
                    }
                    break;

                default:
                    throw new \Exception('Tipo de cupón no válido.');
            }

            // Deduct usage
            $coupon->decrement('uses');

            return [
                'coupon' => $coupon->nombre,
                'discount_applied' => true,
            ];
        });
    }
}

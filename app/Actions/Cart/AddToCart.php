<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartAddon;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class AddToCart
{
    public function __invoke(string $sessionId, string $storeSerial, int $productId, int $quantity, array $selectedAddonIds = []): Cart
    {
        return DB::transaction(function () use ($sessionId, $storeSerial, $productId, $quantity, $selectedAddonIds) {
            $product = Product::with('addons')->findOrFail($productId);

            if (! $product->active) {
                throw new \Exception('Este producto no está disponible.');
            }

            $store = Store::where('serial', $storeSerial)->firstOrFail();

            // Check if same product (without addons) is already in cart
            $existing = Cart::active()
                ->byUser($sessionId)
                ->byStore($storeSerial)
                ->where('product', $productId)
                ->whereDoesntHave('addons')
                ->first();

            if ($existing && empty($selectedAddonIds)) {
                $newQty = $existing->cant + $quantity;
                $newPrice = ($product->number * $newQty);
                $existing->update(['cant' => $newQty, 'price' => $newPrice]);
                return $existing;
            }

            // Calculate price with addons
            $addonPrice = 0;
            if (! empty($selectedAddonIds)) {
                $addonPrice = ProductAddon::whereIn('id', $selectedAddonIds)->sum('precio');
            }

            $unitPrice = $product->number + $addonPrice;
            $totalPrice = $unitPrice * $quantity;

            $cart = Cart::create([
                'product' => $productId,
                'price' => $totalPrice,
                'dom' => $store->createdby,
                'user' => $sessionId,
                'variation' => $storeSerial,
                'cant' => $quantity,
                'orderC' => null,
                'status' => '0',
            ]);

            // Attach addons
            foreach ($selectedAddonIds as $addonId) {
                CartAddon::create([
                    'noOrder' => $cart->id,
                    'idAditivo' => $addonId,
                    'session' => $sessionId,
                ]);
            }

            return $cart;
        });
    }
}

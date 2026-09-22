<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartAddon;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductStock;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddToCart
{
    public function __invoke(string $sessionId, string $storeSerial, int $productId, int $quantity, array $selectedAddonIds = []): Cart
    {
        return DB::transaction(function () use ($sessionId, $storeSerial, $productId, $quantity, $selectedAddonIds) {
            $store = Store::where('serial', $storeSerial)->firstOrFail();

            $existing = Cart::active()
                ->byUser($sessionId)
                ->byStore($storeSerial)
                ->where('product', $productId)
                ->whereDoesntHave('addons')
                ->lockForUpdate()
                ->first();
            $product = Product::byStore($store->createdby)->active()->lockForUpdate()->findOrFail($productId);
            $stock = ProductStock::where('idProd', $product->id)->lockForUpdate()->first();

            if ($existing && empty($selectedAddonIds)) {
                $newQty = $existing->cant + $quantity;
                if (! $stock || (float) $stock->stock < $newQty) {
                    throw ValidationException::withMessages(['quantity' => ['Stock insuficiente.']]);
                }
                $newPrice = ($product->number * $newQty);
                $existing->update(['cant' => $newQty, 'price' => $newPrice]);

                return $existing;
            }

            if (! $stock || (float) $stock->stock < $quantity) {
                throw ValidationException::withMessages(['quantity' => ['Stock insuficiente.']]);
            }

            // Calculate price with addons
            $addonPrice = 0;
            if (! empty($selectedAddonIds)) {
                $selectedAddonIds = array_values(array_unique($selectedAddonIds));
                $addons = ProductAddon::where('idProd', $product->id)
                    ->where('activo', 1)
                    ->whereIn('id', $selectedAddonIds)
                    ->get();

                if ($addons->count() !== count($selectedAddonIds)) {
                    throw ValidationException::withMessages([
                        'addon_ids' => ['Uno o mas extras no pertenecen al producto o no estan disponibles.'],
                    ]);
                }

                $addonPrice = $addons->sum('precio');
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

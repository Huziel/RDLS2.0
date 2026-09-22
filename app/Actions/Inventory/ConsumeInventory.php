<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ConsumeInventory
{
    public function __invoke(string $storeOwner, Collection|array $requestedQuantities): Collection
    {
        $quantities = collect($requestedQuantities)
            ->mapWithKeys(fn ($quantity, $productId) => [(int) $productId => (float) $quantity])
            ->sortKeys();

        if ($quantities->isEmpty() || $quantities->contains(fn (float $quantity) => $quantity <= 0)) {
            throw ValidationException::withMessages([
                'stock' => ['Las cantidades de inventario deben ser mayores que cero.'],
            ]);
        }

        $products = Product::byStore($storeOwner)
            ->active()
            ->whereIn('id', $quantities->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $quantities->count()) {
            throw ValidationException::withMessages([
                'stock' => ['La venta contiene productos inactivos o ajenos a la tienda.'],
            ]);
        }

        $stocks = ProductStock::whereIn('idProd', $quantities->keys())
            ->orderBy('idProd')
            ->lockForUpdate()
            ->get()
            ->keyBy('idProd');

        foreach ($quantities as $productId => $quantity) {
            $stock = $stocks->get($productId);
            if (! $stock || (float) $stock->stock < $quantity) {
                $productName = $products->get($productId)?->keyy ?? "#{$productId}";
                throw ValidationException::withMessages([
                    'stock' => ["Stock insuficiente para {$productName}."],
                ]);
            }
        }

        foreach ($quantities as $productId => $quantity) {
            $stock = $stocks->get($productId);
            $stock->update(['stock' => (float) $stock->stock - $quantity]);
        }

        return $products;
    }
}

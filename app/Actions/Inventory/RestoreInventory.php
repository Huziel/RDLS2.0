<?php

namespace App\Actions\Inventory;

use App\Models\ProductStock;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RestoreInventory
{
    public function __invoke(Collection|array $returnedQuantities): Collection
    {
        $quantities = collect($returnedQuantities)
            ->mapWithKeys(fn ($quantity, $productId) => [(int) $productId => (float) $quantity])
            ->filter(fn (float $quantity) => $quantity > 0)
            ->sortKeys();

        if ($quantities->isEmpty()) {
            throw ValidationException::withMessages([
                'stock' => ['Las cantidades a reponer deben ser mayores que cero.'],
            ]);
        }

        $stocks = ProductStock::whereIn('idProd', $quantities->keys())
            ->orderBy('idProd')
            ->lockForUpdate()
            ->get()
            ->keyBy('idProd');

        if ($stocks->count() !== $quantities->count()) {
            throw ValidationException::withMessages([
                'stock' => ['No fue posible reponer el inventario: faltan registros de stock.'],
            ]);
        }

        foreach ($quantities as $productId => $quantity) {
            $stock = $stocks->get($productId);
            $stock->update(['stock' => (float) $stock->stock + $quantity]);
        }

        return $stocks;
    }
}

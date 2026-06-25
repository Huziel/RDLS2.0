<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductAddonResource;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Store;
use Illuminate\Http\Request;

class ProductAddonController extends Controller
{
    public function publicIndex($productId)
    {
        $addons = ProductAddon::where('idProd', $productId)->where('activo', 1)->get();
        return ProductAddonResource::collection($addons);
    }

    public function index(Request $request, $productId)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        Product::byStore($store->createdby)->findOrFail($productId);

        $addons = ProductAddon::where('idProd', $productId)->get();

        return ProductAddonResource::collection($addons);
    }

    public function store(Request $request, $productId)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        Product::byStore($store->createdby)->findOrFail($productId);

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'precio' => ['required', 'numeric', 'min:0'],
            'categoria' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string'],
            'activo' => ['boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $addon = ProductAddon::create([
            'idProd' => $productId,
            'nombre' => $validated['nombre'],
            'precio' => $validated['precio'],
            'categoria' => $validated['categoria'] ?? '',
            'descripcion' => $validated['descripcion'] ?? '',
            'activo' => $validated['activo'] ?? 1,
            'stock' => $validated['stock'] ?? 0,
        ]);

        return response()->json([
            'data' => ProductAddonResource::make($addon),
            'message' => 'Aditivo creado.',
        ], 201);
    }

    public function update(Request $request, $productId, $addonId)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        Product::byStore($store->createdby)->findOrFail($productId);

        $addon = ProductAddon::where('idProd', $productId)->findOrFail($addonId);

        $validated = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'precio' => ['sometimes', 'numeric', 'min:0'],
            'categoria' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string'],
            'activo' => ['boolean'],
            'stock' => ['sometimes', 'integer', 'min:0'],
        ]);

        $addon->update($validated);

        return response()->json([
            'data' => ProductAddonResource::make($addon),
            'message' => 'Aditivo actualizado.',
        ]);
    }

    public function destroy(Request $request, $productId, $addonId)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        Product::byStore($store->createdby)->findOrFail($productId);

        ProductAddon::where('idProd', $productId)->where('id', $addonId)->delete();

        return response()->json(['message' => 'Aditivo eliminado.']);
    }
}

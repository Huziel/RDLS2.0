<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $query = Product::byStore($store->createdby)->with(['stock', 'barcode', 'images']);

        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->has('search')) {
            $query->where('keyy', 'like', '%' . $request->search . '%');
        }

        if ($request->has('active')) {
            $query->where('active', $request->active);
        }

        $products = $query->orderByDesc('id')->paginate($request->get('per_page', 20));

        return ProductResource::collection($products);
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();
            $store = Store::byOwner($user->name)->firstOrFail();

            $validated = $request->validate([
                'nombre' => ['required', 'string', 'max:255'],
                'precio' => ['required', 'numeric', 'min:0'],
                'imagen' => ['nullable', 'string'],
                'descripcion' => ['nullable', 'string'],
                'variable' => ['nullable', 'string'],
                'categoria' => ['nullable', 'string'],
                'activo' => ['boolean'],
                'stock' => ['nullable', 'integer', 'min:0'],
                'codigo_barras' => ['nullable', 'string'], // Cambiado a string
                'imagenes' => ['nullable', 'array'],
                'imagenes.*' => ['string'],
            ]);

            // Convertir código de barras a string si existe
            if (isset($validated['codigo_barras']) && !is_null($validated['codigo_barras'])) {
                $validated['codigo_barras'] = (string) $validated['codigo_barras'];
            }

            $product = Product::create([
                'number' => $validated['precio'],
                'keyy' => $validated['nombre'],
                'link' => $validated['imagen'] ?? null,
                'session' => $store->createdby,
                'dscr' => $validated['descripcion'] ?? null,
                'var' => $validated['variable'] ?? null,
                'category' => $validated['categoria'] ?? null,
                'active' => $validated['activo'] ?? true,
            ]);

            if (isset($validated['stock'])) {
                ProductStock::create([
                    'idProd' => $product->id,
                    'stock' => $validated['stock'],
                    'typesd' => null,
                ]);
            }

            if (isset($validated['codigo_barras']) && !empty($validated['codigo_barras'])) {
                ProductBarcode::create([
                    'idProd' => $product->id,
                    'code' => (string) $validated['codigo_barras'],
                ]);
            }

            if (isset($validated['imagenes'])) {
                foreach ($validated['imagenes'] as $img) {
                    ProductImage::create([
                        'picture' => $img,
                        'dom' => $store->createdby,
                        'product' => $product->id,
                    ]);
                }
            }

            return response()->json([
                'data' => ProductDetailResource::make($product->load(['stock', 'barcode', 'images', 'addons'])),
                'message' => 'Producto creado exitosamente.',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $product = Product::byStore($store->createdby)
            ->with(['stock', 'barcode', 'images', 'addons'])
            ->findOrFail($id);

        return response()->json([
            'data' => ProductDetailResource::make($product),
        ]);
    }

    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            $store = Store::byOwner($user->name)->firstOrFail();
            $product = Product::byStore($store->createdby)->findOrFail($id);

            $validated = $request->validate([
                'nombre' => ['sometimes', 'required', 'string', 'max:255'],
                'precio' => ['sometimes', 'required', 'numeric', 'min:0'],
                'imagen' => ['nullable', 'string'],
                'descripcion' => ['nullable', 'string'],
                'variable' => ['nullable', 'string'],
                'categoria' => ['nullable', 'string'],
                'activo' => ['boolean'],
                'stock' => ['nullable', 'integer', 'min:0'],
                // ✅ Cambiar a: acepta cualquier valor que no sea null
                'codigo_barras' => ['nullable'],
                'imagenes' => ['nullable', 'array'],
                'imagenes.*' => ['string'],
            ]);

            // ✅ Normalizar código de barras a string después de la validación
            if (isset($validated['codigo_barras']) && !is_null($validated['codigo_barras'])) {
                $validated['codigo_barras'] = (string) $validated['codigo_barras'];
            }

            $product->update([
                'keyy' => $validated['nombre'] ?? $product->keyy,
                'number' => $validated['precio'] ?? $product->number,
                'link' => $validated['imagen'] ?? $product->link,
                'dscr' => $validated['descripcion'] ?? $product->dscr,
                'var' => $validated['variable'] ?? $product->var,
                'category' => $validated['categoria'] ?? $product->category,
                'active' => $validated['activo'] ?? $product->active,
            ]);

            // Manejar stock
            if (array_key_exists('stock', $validated)) {
                $stock = ProductStock::where('idProd', $product->id)->first();
                if ($stock) {
                    $stock->update(['stock' => $validated['stock']]);
                } else {
                    ProductStock::create([
                        'idProd' => $product->id,
                        'stock' => $validated['stock'],
                        'typesd' => null
                    ]);
                }
            }

            // Manejar código de barras
            if (array_key_exists('codigo_barras', $validated)) {
                $barcode = ProductBarcode::where('idProd', $product->id)->first();
                if ($barcode) {
                    $barcode->update(['code' => (string) $validated['codigo_barras']]);
                } else if (!empty($validated['codigo_barras'])) {
                    ProductBarcode::create([
                        'idProd' => $product->id,
                        'code' => (string) $validated['codigo_barras']
                    ]);
                }
            }

            // Manejar imágenes
            if (array_key_exists('imagenes', $validated)) {
                ProductImage::where('product', $product->id)->delete();
                foreach ($validated['imagenes'] as $img) {
                    ProductImage::create([
                        'picture' => $img,
                        'dom' => $store->createdby,
                        'product' => $product->id,
                    ]);
                }
            }

            return response()->json([
                'data' => ProductDetailResource::make($product->fresh(['stock', 'barcode', 'images', 'addons'])),
                'message' => 'Producto actualizado.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar el producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $product = Product::byStore($store->createdby)->findOrFail($id);
        $product->delete();

        ProductImage::where('product', $id)->delete();

        return response()->json(['message' => 'Producto eliminado.']);
    }

    public function publicShow($id)
    {
        $product = Product::with('images')->findOrFail($id);
        return response()->json(['data' => ProductResource::make($product)]);
    }

    public function publicIndex(Request $request, $serial)
    {
        $store = Store::where('serial', $serial)->firstOrFail();
        $products = Product::byStore($store->createdby)->active()
            ->with(['stock', 'images'])
            ->orderByDesc('id')->paginate($request->get('per_page', 100));
        return ProductResource::collection($products);
    }

    public function searchByBarcode(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $request->validate(['code' => ['required', 'string']]);

        $barcode = ProductBarcode::where('code', $request->code)->first();

        if (! $barcode) {
            return response()->json(['message' => 'Producto no encontrado.'], 404);
        }

        $product = Product::with(['stock', 'barcode'])
            ->where('id', $barcode->idProd)
            ->where('session', $store->createdby)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Producto no encontrado.'], 404);
        }

        return response()->json(['data' => ProductResource::make($product)]);
    }
}

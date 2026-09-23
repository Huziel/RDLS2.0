<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductAddonResource;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\StoreSubscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $query->where('keyy', 'like', '%'.$request->search.'%');
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
            $this->normalizeBarcode($request);

            $validated = $request->validate([
                'nombre' => ['required', 'string', 'max:255'],
                'precio' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
                'imagen' => ['nullable', 'string'],
                'descripcion' => ['nullable', 'string'],
                'variable' => ['nullable', 'string'],
                'categoria' => ['nullable', 'string'],
                'activo' => ['boolean'],
                'stock' => ['nullable', 'integer', 'min:0'],
                'codigo_barras' => ['nullable', 'string', 'max:255'],
                'imagenes' => ['nullable', 'array'],
                'imagenes.*' => ['string'],
            ]);

            // Convertir código de barras a string si existe
            if (isset($validated['codigo_barras']) && ! is_null($validated['codigo_barras'])) {
                $validated['codigo_barras'] = (string) $validated['codigo_barras'];
            }

            $product = DB::transaction(function () use ($user, $store, $validated) {
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $subscription = StoreSubscription::getOrCreateDefault($store->id);
                $maxProducts = $subscription->plan->max_products;

                if ($maxProducts !== null
                    && ! $user->hasRole('super-admin')
                    && Product::byStore($store->createdby)->count() >= $maxProducts) {
                    return null;
                }

                if ($this->hasBarcodeValue($validated['codigo_barras'] ?? null)
                    && $this->barcodeExistsForStore($validated['codigo_barras'], $store->createdby)) {
                    throw ValidationException::withMessages([
                        'codigo_barras' => ['El codigo de barras ya pertenece a otro producto de esta tienda.'],
                    ]);
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

                if ($this->hasBarcodeValue($validated['codigo_barras'] ?? null)) {
                    ProductBarcode::create([
                        'idProd' => $product->id,
                        'code' => (string) $validated['codigo_barras'],
                    ]);
                }

                foreach ($validated['imagenes'] ?? [] as $img) {
                    ProductImage::create([
                        'picture' => $img,
                        'dom' => $store->createdby,
                        'product' => $product->id,
                    ]);
                }

                return $product;
            });

            if (! $product) {
                return response()->json([
                    'message' => 'Alcanzaste el limite de productos de tu plan.',
                ], 422);
            }

            return response()->json([
                'data' => ProductDetailResource::make($product->load(['stock', 'barcode', 'images', 'addons'])),
                'message' => 'Producto creado exitosamente.',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al crear producto', ['exception' => $e]);

            return response()->json([
                'message' => 'Error al crear el producto',
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
            $this->normalizeBarcode($request);
            $validated = $request->validate([
                'nombre' => ['sometimes', 'required', 'string', 'max:255'],
                'precio' => ['sometimes', 'required', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
                'imagen' => ['nullable', 'string'],
                'descripcion' => ['nullable', 'string'],
                'variable' => ['nullable', 'string'],
                'categoria' => ['nullable', 'string'],
                'activo' => ['boolean'],
                'stock' => ['nullable', 'integer', 'min:0'],
                'codigo_barras' => ['nullable', 'string', 'max:255'],
                'imagenes' => ['nullable', 'array'],
                'imagenes.*' => ['string'],
            ]);

            $product = DB::transaction(function () use ($store, $id, $validated) {
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $product = Product::byStore($store->createdby)->lockForUpdate()->findOrFail($id);

                if ($this->hasBarcodeValue($validated['codigo_barras'] ?? null)
                    && $this->barcodeExistsForStore($validated['codigo_barras'], $store->createdby, $product->id)) {
                    throw ValidationException::withMessages([
                        'codigo_barras' => ['El codigo de barras ya pertenece a otro producto de esta tienda.'],
                    ]);
                }

                $product->update([
                    'keyy' => $validated['nombre'] ?? $product->keyy,
                    'number' => $validated['precio'] ?? $product->number,
                    'link' => array_key_exists('imagen', $validated) ? $validated['imagen'] : $product->link,
                    'dscr' => array_key_exists('descripcion', $validated) ? $validated['descripcion'] : $product->dscr,
                    'var' => array_key_exists('variable', $validated) ? $validated['variable'] : $product->var,
                    'category' => array_key_exists('categoria', $validated) ? $validated['categoria'] : $product->category,
                    'active' => $validated['activo'] ?? $product->active,
                ]);

                if (array_key_exists('stock', $validated)) {
                    $stock = ProductStock::where('idProd', $product->id)->lockForUpdate()->first();
                    if ($stock) {
                        $stock->update(['stock' => $validated['stock'], 'typesd' => null]);
                    } else {
                        ProductStock::create([
                            'idProd' => $product->id,
                            'stock' => $validated['stock'],
                            'typesd' => null,
                        ]);
                    }
                }

                if (array_key_exists('codigo_barras', $validated)) {
                    $barcode = ProductBarcode::where('idProd', $product->id)->first();
                    if (! $this->hasBarcodeValue($validated['codigo_barras'])) {
                        $barcode?->delete();
                    } elseif ($barcode) {
                        $barcode->update(['code' => $validated['codigo_barras']]);
                    } else {
                        ProductBarcode::create([
                            'idProd' => $product->id,
                            'code' => $validated['codigo_barras'],
                        ]);
                    }
                }

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

                return $product->fresh(['stock', 'barcode', 'images', 'addons']);
            });

            return response()->json([
                'data' => ProductDetailResource::make($product),
                'message' => 'Producto actualizado.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Producto no encontrado.'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al actualizar producto', ['exception' => $e, 'product_id' => $id]);

            return response()->json([
                'message' => 'Error al actualizar el producto',
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
        $product = Product::active()
            ->with(['images', 'stock', 'addons' => fn ($query) => $query->where('activo', 1)])
            ->findOrFail($id);

        $resource = ProductResource::make($product)->resolve();
        $resource['stock'] = (float) ($product->stock?->stock ?? 0);
        $resource['aditivos'] = ProductAddonResource::collection($product->addons);

        return response()->json(['data' => $resource]);
    }

    public function publicIndex(Request $request, $serial)
    {
        $store = Store::where('serial', $serial)->firstOrFail();
        $perPage = min(200, max(1, (int) $request->get('per_page', 100)));

        $products = Product::byStore($store->createdby)->active()
            ->with(['stock', 'images'])
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->input('category')))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request) {
                $query->where('keyy', 'like', '%'.$request->input('search').'%')
                    ->orWhere('dscr', 'like', '%'.$request->input('search').'%');
            }))
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();

        $mapped = $products->through(function (Product $product) {
            $resource = ProductResource::make($product)->resolve();
            $resource['stock'] = (float) ($product->stock?->stock ?? 0);
            $resource['aditivos'] = ProductAddonResource::collection(
                $product->addons()->where('activo', 1)->get()
            );

            return $resource;
        });

        return response()->json([
            'data' => $mapped->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function searchByBarcode(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $request->validate(['code' => ['required', 'string']]);

        $product = Product::byStore($store->createdby)
            ->active()
            ->whereHas('barcode', fn ($query) => $query->where('code', $request->code))
            ->with(['stock', 'barcode'])
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Producto no encontrado.'], 404);
        }

        return response()->json(['data' => ProductResource::make($product)]);
    }

    private function barcodeExistsForStore(string $code, string $storeOwner, ?int $exceptProductId = null): bool
    {
        return ProductBarcode::where('code', $code)
            ->when($exceptProductId, fn ($query) => $query->where('idProd', '!=', $exceptProductId))
            ->whereHas('product', fn ($query) => $query->byStore($storeOwner))
            ->exists();
    }

    private function normalizeBarcode(Request $request): void
    {
        if ($request->has('codigo_barras') && is_int($request->input('codigo_barras'))) {
            $request->merge(['codigo_barras' => (string) $request->input('codigo_barras')]);
        }
    }

    private function hasBarcodeValue(mixed $barcode): bool
    {
        return $barcode !== null && $barcode !== '';
    }
}

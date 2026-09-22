<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\ApplyCoupon;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    private function getCartId(Request $request): string
    {
        return $request->header('X-Cart-Token') ?? $request->session()->getId();
    }

    public function index(Request $request, $storeSerial)
    {
        $cartId = $this->getCartId($request);

        $cart = Cart::with(['productData:id,keyy,number,link,var', 'addons.addon'])
            ->active()
            ->byUser($cartId)
            ->byStore($storeSerial)
            ->get();

        $total = $cart->sum('price');

        return response()->json([
            'data' => [
                'cart_token' => $cartId,
                'items' => $cart->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product,
                        'product_name' => $item->productData->keyy ?? '',
                        'product_image' => $item->productData->link ?? '',
                        'product_var' => $item->productData->var ?? '',
                        'price' => (float) $item->price,
                        'quantity' => (int) $item->cant,
                        'addons' => $item->addons->map(fn ($a) => [
                            'id' => $a->idAditivo,
                            'name' => $a->addon->nombre ?? '',
                            'price' => (float) ($a->addon->precio ?? 0),
                        ]),
                    ];
                }),
                'total' => number_format($total, 2, '.', ''),
                'count' => $cart->sum('cant'),
            ],
        ])->header('X-Cart-Token', $cartId);
    }

    public function store(Request $request, $storeSerial)
    {
        $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer'],
        ]);

        $cartId = $this->getCartId($request);

        $cart = app(AddToCart::class)(
            $cartId,
            $storeSerial,
            $request->product_id,
            $request->quantity,
            $request->addon_ids ?? []
        );

        return response()->json([
            'data' => [
                'id' => $cart->id,
                'quantity' => $cart->cant,
                'price' => (float) $cart->price,
            ],
            'message' => 'Producto agregado al carrito.',
        ], 201);
    }

    public function update(Request $request, $storeSerial, $cartId)
    {
        $request->validate(['quantity' => ['required', 'integer', 'min:1']]);

        $cartSessionId = $this->getCartId($request);
        $result = DB::transaction(function () use ($cartSessionId, $storeSerial, $cartId, $request) {
            $item = Cart::active()
                ->byUser($cartSessionId)
                ->byStore($storeSerial)
                ->lockForUpdate()
                ->find($cartId);
            if (! $item) {
                return 'missing';
            }

            $item->load(['productData', 'addons.addon']);
            $product = $item->productData()->lockForUpdate()->first();
            $stock = ProductStock::where('idProd', $item->product)->lockForUpdate()->first();
            if (! $product || ! $product->active || $product->session !== $item->dom) {
                return 'unavailable';
            }
            if (! $stock || (float) $stock->stock < $request->quantity) {
                return 'stock';
            }
            if ($item->addons->contains(fn ($cartAddon) => ! $cartAddon->addon || ! $cartAddon->addon->activo)) {
                return 'addons';
            }

            $unitPrice = (float) $product->number + (float) $item->addons->sum(fn ($cartAddon) => $cartAddon->addon->precio);
            $item->update([
                'cant' => $request->quantity,
                'price' => $unitPrice * $request->quantity,
            ]);

            return 'updated';
        });

        if ($result === 'missing') {
            return response()->json(['message' => 'Item no encontrado en el carrito.'], 404);
        }
        if ($result === 'unavailable') {
            return response()->json(['message' => 'El producto ya no esta disponible.'], 422);
        }
        if ($result === 'stock') {
            return response()->json(['message' => 'Stock insuficiente.'], 422);
        }
        if ($result === 'addons') {
            return response()->json(['message' => 'Uno o mas extras ya no estan disponibles.'], 422);
        }

        return response()->json(['message' => 'Cantidad actualizada.']);
    }

    public function destroy(Request $request, $storeSerial, $cartId)
    {
        $cartSessionId = $this->getCartId($request);
        DB::transaction(function () use ($cartSessionId, $storeSerial, $cartId) {
            $item = Cart::active()
                ->byUser($cartSessionId)
                ->byStore($storeSerial)
                ->lockForUpdate()
                ->find($cartId);
            $item?->delete();
        });

        return response()->json(['message' => 'Producto eliminado del carrito.']);
    }

    public function clear(Request $request, $storeSerial)
    {
        $cartSessionId = $this->getCartId($request);
        DB::transaction(function () use ($cartSessionId, $storeSerial) {
            $items = Cart::active()
                ->byUser($cartSessionId)
                ->byStore($storeSerial)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            Cart::whereIn('id', $items->pluck('id'))->delete();
        });

        return response()->json(['message' => 'Carrito vaciado.']);
    }

    // Coupon
    public function applyCoupon(Request $request, $storeSerial)
    {
        $request->validate(['code' => ['required', 'string']]);

        $cartId = $this->getCartId($request);

        try {
            $result = app(ApplyCoupon::class)($request->code, $cartId, $storeSerial);

            return response()->json([
                'data' => $result,
                'message' => 'Cupón aplicado exitosamente.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

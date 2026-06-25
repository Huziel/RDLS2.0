<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreRating;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    public function products(Request $request)
    {
        $query = Product::with(['store', 'images'])
            ->where('active', 1)
            ->whereHas('store', fn($q) => $q->where('category', '!=', '10'))
            ->whereHas('store.extra', fn($q) => $q->whereNotNull('nombreTienda')->where('nombreTienda', '!=', ''));

        if ($request->has('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('keyy', 'like', $s)->orWhere('dscr', 'like', $s);
            });
        }

        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->has('min_price')) $query->where('number', '>=', $request->min_price);
        if ($request->has('max_price')) $query->where('number', '<=', $request->max_price);
        if ($request->has('store_id')) $query->where('session', Store::find($request->store_id)->createdby ?? '');

        $sort = $request->get('sort', 'recent');
        $products = $query->orderByDesc(match($sort) {
            'price_asc' => 'number', 'price_desc' => 'number',
            'popular' => 'id', default => 'id'
        })->paginate($request->get('per_page', 24));

        $result = $products->through(fn($p) => [
            'id' => $p->id, 'name' => $p->keyy, 'price' => (float) $p->number,
            'image' => $p->link, 'category' => $p->category,
            'store_id' => $p->store->id ?? null,
            'store_name' => $p->store->extra->nombreTienda ?? $p->store->serial ?? '',
            'store_serial' => $p->store->serial ?? '',
        ]);

        return response()->json($result);
    }

    public function categories()
    {
        $cats = Product::where('active', 1)->whereNotNull('category')
            ->where('category', '!=', 'null')->distinct()->pluck('category');
        return response()->json(['data' => $cats]);
    }

    public function show($id)
    {
        $product = Product::with(['store.extra', 'images', 'addons'])->findOrFail($id);
        $related = Product::where('category', $product->category)->where('id', '!=', $id)
            ->where('active', 1)->limit(8)->get(['id', 'keyy', 'number', 'link']);

        return response()->json(['data' => [
            'id' => $product->id, 'name' => $product->keyy, 'price' => (float) $product->number,
            'image' => $product->link, 'description' => $product->dscr,
            'category' => $product->category, 'images' => $product->images->pluck('picture'),
            'store' => [
                'id' => $product->store->id ?? null, 'name' => $product->store->extra->nombreTienda ?? $product->store->serial ?? '',
                'serial' => $product->store->serial ?? '', 'phone' => $product->store->phone ?? '',
            ],
            'related' => $related->map(fn($r) => ['id' => $r->id, 'name' => $r->keyy, 'price' => (float) $r->number, 'image' => $r->link]),
            'addons' => $product->addons->map(fn($a) => ['id' => $a->id, 'name' => $a->nombre, 'price' => (float) $a->precio]),
        ]]);
    }

    public function stores(Request $request)
    {
        $query = Store::with('extra')
            ->where('category', '!=', '10')
            ->whereHas('extra', fn($q) => $q->whereNotNull('nombreTienda')->where('nombreTienda', '!=', ''));
        if ($request->has('search')) {
            $s = '%' . $request->search . '%';
            $query->whereHas('extra', fn($q) => $q->where('nombreTienda', 'like', $s));
        }
        $stores = $query->paginate($request->get('per_page', 12));
        $result = $stores->through(fn($s) => [
            'id' => $s->id, 'name' => $s->extra->nombreTienda ?? $s->serial ?? '',
            'serial' => $s->serial, 'phone' => $s->phone, 'category' => $s->category,
            'logo' => $s->logojpg, 'address' => $s->adress,
            'product_count' => Product::where('session', $s->createdby)->where('active', 1)->count(),
            'sample_products' => Product::where('session', $s->createdby)->where('active', 1)
                ->limit(4)->pluck('link')->filter()->values(),
        ]);
        return response()->json($result);
    }

    public function storeProfile($id)
    {
        $store = Store::with('extra')->findOrFail($id);
        $products = Product::where('session', $store->createdby)->where('active', 1)
            ->limit(20)->get(['id', 'keyy', 'number', 'link', 'category']);
        $ratings = StoreRating::with('user:id,name')->where('idTieda', $id)->orderByDesc('id')->limit(20)->get();
        return response()->json(['data' => [
            'id' => $store->id, 'name' => $store->extra->nombreTienda ?? $store->serial ?? '',
            'serial' => $store->serial, 'phone' => $store->phone,
            'logo' => $store->logojpg, 'address' => $store->adress,
            'description' => $store->extra->texto1 ?? '',
            'horario' => $store->extra->horario ?? '',
            'facebook' => $store->extra->facebook ?? '', 'instagram' => $store->extra->instagram ?? '',
            'products' => $products->map(fn($p) => ['id' => $p->id, 'name' => $p->keyy, 'price' => (float) $p->number, 'image' => $p->link]),
            'ratings' => $ratings->map(fn($r) => ['user' => $r->user->name ?? '', 'rating' => (int) $r->calificacion, 'comment' => $r->comentario]),
        ]]);
    }

    public function rateStore(Request $request, $storeId)
    {
        $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string']);
        StoreRating::create(['idTieda' => $storeId, 'idUser' => $request->user()->id, 'calificacion' => $request->rating, 'comentario' => $request->comment]);
        return response()->json(['message' => 'Valoración guardada.'], 201);
    }

    // Aggregated cart across all stores
    public function aggregatedCart(Request $request)
    {
        $cartToken = $request->header('X-Cart-Token') ?? $request->session()->getId();
        $items = Cart::with(['productData:id,keyy,number,link', 'addons.addon'])
            ->where('user', $cartToken)->where('status', '0')->get();

        // Group by store
        $grouped = $items->groupBy('variation')->map(function ($items, $serial) {
            $store = Store::where('serial', $serial)->first();
            return [
                'store_serial' => $serial,
                'store_name' => $store?->extra?->nombreTienda ?? $store->serial ?? 'Tienda',
                'store_id' => $store->id ?? null,
                'items' => $items->map(fn($i) => [
                    'id' => $i->id, 'product_name' => $i->productData->keyy ?? '',
                    'product_image' => $i->productData->link ?? '', 'price' => (float) $i->price,
                    'quantity' => (int) $i->cant,
                ]),
                'total' => $items->sum('price'),
                'count' => $items->sum('cant'),
            ];
        })->values();

        return response()->json(['data' => [
            'stores' => $grouped,
            'total_items' => $items->sum('cant'),
            'total_price' => $items->sum('price'),
        ]]);
    }
}

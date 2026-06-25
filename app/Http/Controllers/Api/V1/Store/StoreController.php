<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreExtraResource;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\StoreColor;
use App\Models\StoreExtra;
use App\Models\StoreFeature;
use App\Models\StorePassword;
use App\Models\StoreTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StoreController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        return response()->json([
            'data' => StoreResource::make($store->load('extra')),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:10'],
            'adress' => ['nullable', 'string'],
            'lat' => ['nullable', 'string'],
            'long' => ['nullable', 'string'],
            'logojpg' => ['nullable', 'string'],
        ]);

        // Allow passing 'logo' as alias for 'logojpg'
        if ($request->has('logo') && !$request->has('logojpg')) {
            $validated['logojpg'] = $request->logo;
        }

        if ($request->has('phone') && $request->phone) {
            $exists = Store::where('phone', $request->phone)
                ->where('id', '!=', $store->id)
                ->first();
            if ($exists) {
                return response()->json([
                    'message' => 'Ya existe otra tienda con el mismo numero telefonico.',
                ], 422);
            }
        }

        $store->update($validated);

        return response()->json([
            'data' => StoreResource::make($store->fresh('extra')),
            'message' => 'Datos de la tienda actualizados.',
        ]);
    }

    // Settings - Extra info
    public function extraInfo(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        if ($request->isMethod('get')) {
            $extra = StoreExtra::where('idTienda', $store->id)->first();
            return response()->json(['data' => $extra ? StoreExtraResource::make($extra) : null]);
        }

        $validated = $request->validate([
            'nombre_tienda' => ['nullable', 'string', 'max:255'],
            'horario' => ['nullable', 'string'],
            'texto1' => ['nullable', 'string'],
            'texto2' => ['nullable', 'string'],
            'facebook' => ['nullable', 'string'],
            'instagram' => ['nullable', 'string'],
            'youtube' => ['nullable', 'string'],
            'mercado_libre' => ['nullable', 'string'],
            'transferencia1' => ['nullable', 'string'],
            'transferencia2' => ['nullable', 'string'],
            'nombre_banco1' => ['nullable', 'string'],
            'nombre_banco2' => ['nullable', 'string'],
            'nombre_propietario1' => ['nullable', 'string'],
            'nombre_propietario2' => ['nullable', 'string'],
            'booking_days' => ['nullable', 'string'],
            'booking_hours' => ['nullable', 'string'],
            'sections' => ['nullable', 'string'],
        ]);

        $extra = StoreExtra::where('idTienda', $store->id)->first();

        // Only include fields that were actually sent in the request
        $data = [];
        foreach ($validated as $key => $value) {
            if ($key === 'sections') continue; // handled separately below
            if (!$request->has($key)) continue;
            $columnMap = [
                'nombre_tienda' => 'nombreTienda',
                'horario' => 'horario',
                'texto1' => 'texto1',
                'texto2' => 'texto2',
                'facebook' => 'facebook',
                'instagram' => 'instagram',
                'youtube' => 'youtube',
                'mercado_libre' => 'mercadoLibre',
                'transferencia1' => 'transf1',
                'transferencia2' => 'transf2',
                'nombre_banco1' => 'nameBanc1',
                'nombre_banco2' => 'nameBanc2',
                'nombre_propietario1' => 'namePrope1',
                'nombre_propietario2' => 'namePrope2',
                'booking_days' => 'booking_days',
                'booking_hours' => 'booking_hours',
            ];
            if (isset($columnMap[$key])) {
                $data[$columnMap[$key]] = $value;
            }
        }
        // Sections: save directly from raw request body
        $all = $request->all();
        if (array_key_exists('sections', $all)) {
            $data['sections'] = $all['sections'];
        }

        // Sections: save directly from raw request body
        $all = $request->all();
        if (array_key_exists('sections', $all)) {
            $data['sections'] = $all['sections'];
        }

        if ($extra) {
            $extra->update($data);
        } else {
            $data['idTienda'] = $store->id;
            $extra = StoreExtra::create($data);
        }

        return response()->json([
            'data' => StoreExtraResource::make($extra->fresh()),
            'message' => 'Informacion adicional actualizada.',
            'debug_sections_saved' => isset($data['sections']),
        ]);
    }

    public function banner(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $request->validate(['banner' => ['required', 'string']]);

        $extra = StoreExtra::where('idTienda', $store->id)->first();

        if ($extra) {
            $extra->update(['banner' => $request->banner]);
        } else {
            $extra = StoreExtra::create([
                'idTienda' => $store->id,
                'banner' => $request->banner,
            ]);
        }

        return response()->json([
            'data' => StoreExtraResource::make($extra),
            'message' => 'Banner actualizado.',
        ]);
    }

    // Colors
    public function colors(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        if ($request->isMethod('get')) {
            $colors = StoreColor::where('idStore', $store->id)->first();
            return response()->json(['data' => $colors]);
        }

        $validated = $request->validate([
            'coloruno' => ['required', 'string'],
            'colordos' => ['required', 'string'],
            'colortres' => ['required', 'string'],
            'colorcuatro' => ['required', 'string'],
            'colorcinco' => ['required', 'string'],
        ]);

        $colors = StoreColor::where('idStore', $store->id)->first();

        if ($colors) {
            $colors->update($validated);
        } else {
            $validated['idStore'] = $store->id;
            $colors = StoreColor::create($validated);
        }

        return response()->json([
            'data' => $colors,
            'message' => 'Colores actualizados.',
        ]);
    }

    // Theme
    public function theme(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        if ($request->isMethod('get')) {
            $theme = StoreTheme::where('userId', $store->id)->first();
            return response()->json(['data' => $theme]);
        }

        $request->validate(['tema_id' => ['required']]);

        $theme = StoreTheme::where('userId', $store->id)->first();

        if ($theme) {
            $theme->update(['temaId' => $request->tema_id]);
        } else {
            $theme = StoreTheme::create([
                'userId' => $store->id,
                'temaId' => $request->tema_id,
            ]);
        }

        return response()->json([
            'data' => $theme,
            'message' => 'Tema actualizado.',
        ]);
    }

    // Password protection for catalog
    public function catalogPassword(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        if ($request->isMethod('get')) {
            $pass = StorePassword::where('idTienda', $store->id)->first();
            return response()->json(['data' => ['has_password' => (bool) $pass]]);
        }

        $request->validate(['catalog_pass' => 'required|min:4']);

        $pass = StorePassword::where('idTienda', $store->id)->first();
        $hash = Hash::make($request->catalog_pass);

        if ($pass) {
            $pass->update(['keyMenu' => $hash]);
        } else {
            StorePassword::create([
                'idTienda' => $store->id,
                'keyMenu' => $hash,
            ]);
        }

        return response()->json(['message' => 'Contraseña del catálogo actualizada.']);
    }

    public function removeCatalogPassword(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        StorePassword::where('idTienda', $store->id)->delete();

        return response()->json(['message' => 'Contraseña del catálogo eliminada.']);
    }

    public function verifyCatalogPassword(Request $request, $serial = null)
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $storeId = $request->store_id;
        if (!$storeId && $serial) {
            $store = Store::where('serial', $serial)->first();
            $storeId = $store?->id;
        }
        if (!$storeId) {
            return response()->json(['message' => 'Tienda no encontrada.'], 404);
        }

        $pass = StorePassword::where('idTienda', $storeId)->first();

        if (! $pass || ! Hash::check($request->password, $pass->keyMenu)) {
            return response()->json(['message' => 'Contraseña incorrecta.'], 403);
        }

        return response()->json(['message' => 'Acceso concedido.']);
    }

    // Features toggle (shipping form)
    public function featureToggle(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $request->validate([
            'component_id' => ['required', 'integer'],
            'active' => ['required', 'boolean'],
        ]);

        $feature = StoreFeature::where('idTienda', $store->id)
            ->where('idComponent', $request->component_id)
            ->first();

        if ($feature) {
            $feature->update(['active' => $request->active]);
        } else {
            $feature = StoreFeature::create([
                'idTienda' => $store->id,
                'idComponent' => $request->component_id,
                'active' => $request->active,
            ]);
        }

        return response()->json([
            'data' => $feature,
            'message' => 'Característica actualizada.',
        ]);
    }

    public function getFeatures(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $features = StoreFeature::where('idTienda', $store->id)->get();

        return response()->json(['data' => $features]);
    }

    // Shipping costs
    public function shippingCosts(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        if ($request->isMethod('get')) {
            $costs = $store->color; // Format: "10|4|10|1" (base|medio|largo|tiempo)
            $parts = explode('|', $costs);
            return response()->json([
                'data' => [
                    'precio_base' => $parts[0] ?? '10',
                    'precio_medio' => $parts[1] ?? '4',
                    'precio_largo' => $parts[2] ?? '10',
                    'tiempo_comida' => $parts[3] ?? '1',
                ],
            ]);
        }

        $request->validate([
            'precio_base' => ['required', 'numeric'],
            'precio_medio' => ['required', 'numeric'],
            'precio_largo' => ['required', 'numeric'],
            'tiempo_comida' => ['required', 'numeric'],
        ]);

        $store->update([
            'color' => implode('|', [
                $request->precio_base,
                $request->precio_medio,
                $request->precio_largo,
                $request->tiempo_comida,
            ]),
        ]);

        return response()->json(['message' => 'Costos de envío actualizados.']);
    }

    // Public store theme
    private function parseShippingCosts($store)
    {
        $parts = explode('|', $store->color ?? '');
        return [
            'base' => floatval($parts[0] ?? 10),
            'medio' => floatval($parts[1] ?? 4),
            'largo' => floatval($parts[2] ?? 10),
        ];
    }

    public function publicTheme($serial)
    {
        $store = Store::with('extra')->where('serial', $serial)->firstOrFail();
        $theme = StoreTheme::where('userId', $store->id)->first();
        $hasPassword = StorePassword::where('idTienda', $store->id)->exists();
        $photos = \App\Models\MediaPhoto::where('idLog', $store->owner->id ?? 0)->orderByDesc('id')->limit(12)->get();
        $videos = \App\Models\MediaVideo::where('idLog', $store->owner->id ?? 0)->orderByDesc('id')->limit(6)->get();
        $sections = null;
        if ($store->extra && $store->extra->sections) {
            try { $sections = json_decode($store->extra->sections, true); } catch(\Exception $e) {}
        }
        $colors = \App\Models\StoreColor::where('idStore', $store->id)->first();
        $features = StoreFeature::where('idTienda', $store->id)->get();
        return response()->json(['data' => [
            'tema_id' => $theme->temaId ?? 1,
            'extra' => $store->extra ? StoreExtraResource::make($store->extra) : null,
            'has_password' => $hasPassword,
            'colors' => $colors ? [
                'primary' => $colors->coloruno, 'secondary' => $colors->colordos,
                'success' => $colors->colortres, 'dark' => $colors->colorcuatro, 'light' => $colors->colorcinco,
            ] : null,
            'features' => [
                'shipping' => $features->isEmpty() ? true : $features->contains(fn($f) => $f->idComponent == 1 && $f->active == 1),
                'pickup' => $features->isEmpty() ? true : $features->contains(fn($f) => $f->idComponent == 2 && $f->active == 1),
            ],
            'shipping_costs' => $this->parseShippingCosts($store),
            'location' => ['lat' => $store->lat, 'lng' => $store->long, 'adress' => $store->adress],
            'gallery' => ['photos' => $photos->map(fn($p) => $p->urlFoto), 'videos' => $videos->map(fn($v) => $v->urlVideo)],
            'sections' => $sections,
        ]]);
    }

    // Public availability
    public function publicAvailability($serial)
    {
        $store = Store::with('extra')->where('serial', $serial)->firstOrFail();
        $extra = $store->extra;
        return response()->json(['data' => [
            'booking_days' => $extra->booking_days ?? null,
            'booking_hours' => $extra->booking_hours ?? '09:00-18:00',
        ]]);
    }

    // Public store info
    public function publicShow($serial)
    {
        $store = Store::with('extra')->where('serial', $serial)->firstOrFail();
        return response()->json(['data' => StoreResource::make($store)]);
    }

    // Ratings
    public function ratings(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $ratings = StoreRating::with('user:id,name')
            ->where('idTieda', $store->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $ratings]);
    }
}

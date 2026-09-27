<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\StoreCatalogCapability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStoreCatalogCapability
{
    public function __construct(private readonly StoreCatalogCapability $capabilities) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->resolveStore($request);
        $this->capabilities->authorize($store, $request->header('X-Store-Capability'));

        return $next($request);
    }

    private function resolveStore(Request $request): Store
    {
        $serial = $request->route('serial') ?? $request->route('storeSerial');
        if (! is_string($serial) || $serial === '') {
            abort(404);
        }

        $stores = Store::whereRaw('LOWER(serial) = ?', [mb_strtolower($serial)])->limit(2)->get();
        abort_unless($stores->count() === 1, 404);

        $store = $stores->first();
        if ($request->route('serial') !== null) {
            $request->route()->setParameter('serial', $store->serial);
        }
        if ($request->route('storeSerial') !== null) {
            $request->route()->setParameter('storeSerial', $store->serial);
        }

        return $store;
    }
}

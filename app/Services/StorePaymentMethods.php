<?php

namespace App\Services;

use App\Models\MercadoPagoAccount;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StorePaymentSetting;
use Illuminate\Support\Facades\Schema;

class StorePaymentMethods
{
    public function resolve(Store $store, bool $lockForUpdate = false): array
    {
        $extraQuery = $store->extra();
        $extra = $lockForUpdate ? $extraQuery->lockForUpdate()->first() : $extraQuery->first();
        $ownerId = $store->relationLoaded('owner') ? $store->owner?->id : $store->owner()->value('id');
        $accountQuery = MercadoPagoAccount::where('idLog', $ownerId);
        $account = $ownerId
            ? ($lockForUpdate ? $accountQuery->lockForUpdate()->first() : $accountQuery->first())
            : null;
        $setting = null;
        if (Schema::hasTable('store_payment_settings')) {
            $settingQuery = StorePaymentSetting::where('store_id', $store->id);
            $setting = $lockForUpdate ? $settingQuery->lockForUpdate()->first() : $settingQuery->first();
        }

        return [
            'cash' => true,
            'bank_transfer' => $extra !== null && (
                $this->complete($extra->nameBanc1, $extra->namePrope1, $extra->transf1)
                || $this->complete($extra->nameBanc2, $extra->namePrope2, $extra->transf2)
            ),
            'mercado_pago' => $account !== null
                && $this->complete($account->merchantId, $account->secretKey, $account->publicKey),
            'cash_on_delivery' => (bool) ($setting?->cash_on_delivery_enabled)
                && $this->localShippingEnabled($store, $lockForUpdate),
        ];
    }

    public function enabled(Store $store, string $method, bool $lockForUpdate = false): bool
    {
        return $this->resolve($store, $lockForUpdate)[$method] ?? false;
    }

    private function complete(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    private function localShippingEnabled(Store $store, bool $lockForUpdate): bool
    {
        $query = StoreFeature::where('idTienda', $store->id)->orderBy('id');
        $features = $lockForUpdate
            ? $query->lockForUpdate()->get(['idComponent', 'active'])
            : $query->get(['idComponent', 'active']);
        if ($features->isEmpty()) {
            return true;
        }

        return $features->contains(
            fn (StoreFeature $feature) => (int) $feature->idComponent === 1 && (int) $feature->active === 1
        );
    }
}

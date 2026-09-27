<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StorePassword;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreCatalogCapability
{
    private const VERSION = 1;

    public function issue(Store $store, StorePassword $password): array
    {
        if ((int) $password->idTienda !== (int) $store->id) {
            throw new HttpException(403, 'Capability de catalogo invalida.');
        }

        $expiresAt = now()->addMinutes(max(1, (int) config('store_catalog.capability_ttl_minutes', 30)));
        $payload = [
            'version' => self::VERSION,
            'store_id' => (int) $store->id,
            'serial' => (string) $store->serial,
            'exp' => $expiresAt->getTimestamp(),
            'password_fingerprint' => $this->fingerprint($password),
        ];

        return [
            'capability' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function authorize(Store $store, mixed $token): void
    {
        $passwords = $store->password()->limit(2)->get();
        if ($passwords->isEmpty()) {
            return;
        }
        if ($passwords->count() !== 1) {
            throw new HttpException(403, 'Capability de catalogo invalida.');
        }

        $this->authorizeWithPassword($store, $passwords->first(), $token);
    }

    public function authorizeWithPassword(Store $store, ?StorePassword $password, mixed $token): void
    {
        if (! $password) {
            return;
        }
        if ((int) $password->idTienda !== (int) $store->id) {
            throw new HttpException(403, 'Capability de catalogo invalida.');
        }

        if (! is_string($token) || trim($token) === '') {
            throw new HttpException(401, 'Capability de catalogo requerida.');
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new HttpException(403, 'Capability de catalogo invalida.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::VERSION
            || (int) ($payload['store_id'] ?? 0) !== (int) $store->id
            || ! is_string($payload['serial'] ?? null)
            || ! hash_equals((string) $store->serial, $payload['serial'])
            || ! is_int($payload['exp'] ?? null)
            || Carbon::now()->getTimestamp() >= $payload['exp']
            || ! is_string($payload['password_fingerprint'] ?? null)
            || ! hash_equals($this->fingerprint($password), $payload['password_fingerprint'])) {
            throw new HttpException(403, 'Capability de catalogo invalida.');
        }
    }

    public function isProtected(Store $store): bool
    {
        return $store->relationLoaded('password')
            ? $store->password !== null
            : $store->password()->exists();
    }

    private function fingerprint(StorePassword $password): string
    {
        return hash('sha256', implode('|', [
            (string) $password->id,
            (string) $password->idTienda,
            (string) $password->keyMenu,
        ]));
    }
}

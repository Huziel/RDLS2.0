<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflict;
use App\Models\OrderAuditEvent;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderIdempotency
{
    public function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '' || strlen($key) > 100) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['El encabezado Idempotency-Key es obligatorio y debe tener maximo 100 caracteres.'],
            ]);
        }

        return trim($key);
    }

    public function eventKey(PurchaseOrder $order, string $operation, string $key): string
    {
        return hash('sha256', implode('|', [$order->serial, $order->id, $operation, $key]));
    }

    public function find(string $eventKey, string $requestHash): ?OrderAuditEvent
    {
        $event = OrderAuditEvent::where('event_key', $eventKey)->lockForUpdate()->first();
        if ($event && ! hash_equals($event->request_hash, $requestHash)) {
            throw new IdempotencyConflict('La clave de idempotencia ya fue utilizada con otro contenido.');
        }

        return $event;
    }

    public function record(
        PurchaseOrder $order,
        int $storeId,
        ?int $actorId,
        string $actorType,
        string $eventType,
        string $eventKey,
        string $requestHash,
        ?array $previous,
        ?array $next,
        int $responseStatus,
        array $responseData,
    ): OrderAuditEvent {
        return OrderAuditEvent::create([
            'order_id' => $order->id,
            'store_id' => $storeId,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'event_type' => $eventType,
            'event_key' => $eventKey,
            'request_hash' => $requestHash,
            'previous' => $this->sanitize($previous),
            'next' => $this->sanitize($next),
            'response_status' => $responseStatus,
            'response_data' => $this->sanitize($responseData),
            'created_at' => now(),
        ]);
    }

    public function hash(array $payload): string
    {
        $normalized = $this->normalize($payload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function sanitize(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $blocked = ['tel', 'telefono', 'phone', 'token', 'path', 'disk', 'address', 'direccion', 'lat', 'lng', 'long'];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}

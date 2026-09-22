# Estados de ordenes, cancelacion y reposicion de stock

FASE 5 agrega estados explícitos a las ordenes publicas y una cancelacion atomica e idempotente que devuelve el inventario y revierte la lealtad exactamente una vez.

## Estados de orden

En `ordencompra`, `order_state` acepta los valores tipados en `PurchaseOrder`:

```text
null        . pending       compra creada, aun no pagada
'paid'      . (cart status 3 y/o mercadopago paid)
'cancelled' . cancelacion idempotente
```

- `isPaid()`: `order_state = 'paid'` o cualquier linea del carrito del folio con `status = 3`.
- `isCancelled()`: `order_state = 'cancelled'` O existe `cancelled_at`.

`mercadopago.payment_id` guarda el pago confirmado por el webhook; `order_state` de la orden pagada tambien se marca `paid` en la confirmacion.

## Cancelacion

`App\Actions\Order\CancelOrder` corre dentro de una transaccion con `FOR UPDATE`:

```text
bloquear orden
  si ya cancelada      -> idempotent: true, sin tocar nada
  capturar wasPaid     -> primer cancelado por admin de una orden pagada marca refund_required
  si restock_key null
    agrupar carrito por producto
    RestoreInventory   -> bloquea saldos y repone (una sola vez)
    reverseLoyalty     -> devuelve canjes y revierte acumulaciones
    order_state=cancelled, cancelled_at=now,
    restock_key=sha256("restock:<folio>")   <- baliza unica
  si restock_key existe
    solo asegurar order_state=cancelled
```

Respuesta: `{ status, idempotent, restocked, reversed, refund_required }`.

La unica columna `restock_key` (unique) garantiza que el stock se reponga una sola vez aunque lleguen cancelaciones repetidas o concurrentes.

### Reversion de lealtad

- Canje de la misma orden: `LoyaltyPoint::addPoints` con tipo `redeem_reverse` y la referencia `checkout:{checkout_key}` del asiento original.
- Acumulacion de la misma orden: `LoyaltyPoint::redeemPoints` con tipo `earn_reverse` y referencia `earn_reverse:{folio}`.
- Ambos asientos son idempotentes por `(store, type, reference)`; la reversion nunca deja saldos negativos.

## Reglas publicas

- `POST /api/v1/stores/{serial}/orders/{order}/cancel` exige el `X-Cart-Token` de la orden y la misma tienda; si no coincide responde 404.
- Una orden pagada no puede cancelarse publicamente (422); la cancelacion de una orden pagada es responsabilidad del admin autenticado, que recibe `refund_required: true` para gestionar un reembolso manual por la API oficial.
- Cancelar no reembolsa dinero: el reembolso de una orden pagada es un paso separado y manual.

## Migracion `2026_09_21_000005_online_order_states_and_cancellation`

- Preflight: exige `ordencompra` y `mercadopago`, y aborta si ya existen `restock_key` duplicados.
- Agrega `ordencompra.order_state` (string 20), `cancelled_at` (timestamp), `restock_key` (string 64) con unique `online_restock_unique`, y `mercadopago.payment_id` (unsigned bigint).
- Cada DDL es condicional (`hasColumn` / `hasIndex`) para reintentos parciales; `down()` revierte columnas e indice.

Se valida sobre SQLite `:memory:` en PHPUnit y sobre MySQL 8 desechable; no se ejecuta contra produccion.

## Pruebas

`tests/Feature/OrderCancellationTest.php` cubre: cancelacion publica restaura y es idempotente, token/tienda incorrectos dan 404, orden pagada no cancela publicamente, cancelacion admin marca `refund_required`, earn y earn-reverse mantienen saldo estable, redeem-reverse no deja saldo negativo, y cancelacion repetida (admin) nunca repone dos veces.
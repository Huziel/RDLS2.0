# FASE 6B — Lote correctivo de seguridad runtime y coherencia de entregas (reporte)

Fecha: 2026-09-22 · Rama: `recuperacion` (HEAD `2985648`) · Sin commit ni push (pendiente autorización)
Stack de pruebas: PHP 8.2.12, Laravel 11.54.0, `php artisan test` sobre SQLite `:memory:` (forzado en `phpunit.xml`/`tests/TestCase.php`). No se ejecutó nada sobre producción ni MariaDB.

## Resultados

| Validación | Comando | Resultado |
|---|---|---|
| Suite completa | `php artisan test` | **104 passed (599 assertions)**, 4.22 s |
| Lote 6B (tests nuevos/adaptados) | `php artisan test --filter "DeliverySecurityFixTest\|DeliveryMigrationFixTest\|UnknownApiRouteTest"` | 24 passed (225 assertions) |
| Sintaxis | `php -l` sobre 13 archivos del lote | 0 errores |
| Estilo | `php vendor/bin/pint --test` sobre archivos del lote | passed (se aplicó `php vendor/bin/pint` y se revalidó) |
| Artefactos | `public/uploads/.tmp-6b-*.png` | 0 restos (limpieza + `tearDown` defensivo con glob) |

## Contrato cumplido (por punto)

| # | Contrato | Archivo:línea(s) clave | Comportamiento |
|---|---|---|---|
| P1 | Completar entrega / subir evidencia sin autorización | `app/Http/Controllers/Api/V1/DeliveryController.php` `uploadEvidence()` + `isValidEvidenceImage()`; `getLocation()` (P3); lecturas con `whereDoesntHave('purchaseOrder', cancelada)` | Evidencia: 404/409/422 fail-closed; solo rider asignado con ShippingOrder activa; imagen local bajo `/uploads/`, sin `..`, `mime_content_type` imagen. Evidencia previa en `imageevidence` (schema sin autor) → 409 idempotente asumido y documentado. `completeOrder()` → 503 si `config('delivery.completion_enabled')` es false. |
| P2 | Estado pago legacy preservado | `app/Http/Controllers/Api/V1/OrderController.php` (`index` usa `$order->isPaid()`); `DeliveryController.php` `emitOrder()/acceptOrder()/cancelOrder()` (filtros `status != '3'`) | `accept`/`release`/`emit` no tocan renglones con `cart.status='3'`; `order_state` no se sobreescribe. Probado con orden legacy (`order_state` NULL + renglón '3'). |
| P3 | GPS de otro rider / owner sin vínculo | `getLocation()`; ruta movida fuera de `role_or_permission` en `routes/api.php` | Rider: solo `delivery_id === auth()->id()`; owner: rol + link en tienda propia + ShippingOrder `status IN ('1','2')`; resto 404. |
| P4 | Cambio de documentos revoca verificación | `updateProfile()` en `DeliveryController` | `foto_id`/`foto_domicilio` cambiados respecto al perfil → `verificado='0'` (perfil 1-1 con `idLog`). Sin cambio → se conserva. |
| P5 | Migración `assignment_mode` extendida | `database/migrations/2026_09_22_000001_add_assignment_mode_to_shipping_orders.php` | Backfill `direct`/`pool` por `delivery`; `NOT NULL DEFAULT 'pool'`; índices únicos `ordenCompra` y `imageevidence.orderC` con preflight duplicados (RuntimeException, nunca borra filas); `down()` deja nullable y retira índices. |
| P6 | `confirmPayment` guard + listado canónico | `OrderController::confirmPayment()` | Cancelada → 409; ya pagada → 200 idempotente; `index.paid` canónico por `isPaid()`. |
| P7 | Backfill de roles legacy | `database/migrations/2026_09_22_000002_backfill_delivery_roles.php` | Crea permisos `orders.*` + `delivery.*`, roles `store-owner`/`deliver`; owner = `createdby` de tienda en `liks`; rider = type '3' activo + `verificado='1'` + vínculo en `anexosdeliver`. Guardado contra duplicados (idempotente). |
| P8 | Cancelación de compra marca entrega terminal y la oculta | `OrderController::cancel()/publicCancel()` (transacción + `terminateShippingForCancelledOrder()` con guard `Schema::hasTable`); listados excluyen canceladas | `order_state`//`ordenenvio.status='4'`; `availableOrders`, `activeOrder` y aceptación/release no muestran ni aceptan órdenes canceladas. |
| P9 | Payload bundle sin `assignment_mode` | `app/Http/Requests/Delivery/EmitShippingOrderRequest::prepareForValidation()` | `delivery_id` presente ⇒ `direct`; ausente ⇒ `pool` (deprecación documentada, FASE 7 corrige UI). |
| P10 | Password SMTP en respuestas | `app/Models/SiteSetting.php` `$hidden` | `mail_password` nunca se serializa. |
| P11 | Doble aceptación | `acceptOrder()` | Rider con entrega activa (`status` '1' o '2' en alguna tienda) → 409. |
| P12 | Completar entrega bajo llave de configuración | `config/delivery.php` | `completion_enabled` default false; `completeOrder` → 503. |
| P13 | `/api` y `/api/*` devuelven JSON 404 | `routes/web.php` (rutas explícitas antes del catch-all SPA); `tests/Feature/UnknownApiRouteTest.php` (GET+POST, raíz y trailing slash; regresión SPA profunda) | No más HTML del bundle para rutas API inexistentes. |

## Archivos tocados en el lote 6B

Modificados: `app/Http/Controllers/Api/V1/DeliveryController.php`, `app/Http/Controllers/Api/V1/OrderController.php`, `app/Models/ShippingOrder.php` (constante `STATUS_CANCELLED='4'` + doc de estados), `app/Models/SiteSetting.php`, `app/Http/Requests/Delivery/EmitShippingOrderRequest.php`, `routes/api.php`, `routes/web.php`, `database/migrations/2026_09_22_000001_add_assignment_mode_to_shipping_orders.php`, `tests/Feature/UnknownApiRouteTest.php`.
Nuevos: `config/delivery.php`, `database/migrations/2026_09_22_000002_backfill_delivery_roles.php`, `tests/Feature/DeliverySecurityFixTest.php` (18 tests), `tests/Feature/DeliveryMigrationFixTest.php` (4 tests).

## Supuestos documentados (NO CONFIRMADO en producción)

- `imageevidence` (schema legacy `orderC`, `img`) no registra autor/referencia de solicitud → cualquier evidencia ya presente en una orden → 409 fail-closed al reintentar (idempotencia asumida). Alternativa pendiente de decidir: columna de autor.
- `foto_id` es el documento de identificación (no existe columna `licencia` separada en `datospersonales`).
- La reposición/movimiento de stock por cancelación de entrega NO es parte de este lote (plan: FASE 8). El contrato 6B solo exige "no tocar renglones pagados", verificado.
- Orden de locks propuesto `PurchaseOrder` → `ShippingOrder` (evitar deadlock). NO validado contra MariaDB real (sin acceso a BD de staging por regla del proyecto).
- Concurrencia real (dos riders aceptando simultáneamente) no probada sin MariaDB; mitigación en código: bloqueo de fila + chequeo de estado dentro de la transacción.

## Pendiente (fuera de alcance / fases posteriores)

- Asignación híbrida `pool`/`direct` aprobada e implementada en backend. El OTP seguro de 6 dígitos (expiración, límite de intentos, uso único y acceso exclusivo del cliente reclamante) queda pendiente de FASE 8/9; `completeOrder` permanece cerrado con 503.
- D-01 (verificación global solo superadmin) y D-02 (límite de intentos, expiración, uso único del código) siguen PENDIENTES REVISION según matriz.
- No se ejecutaron npm/npm audit en `frontend/` (sin cambios en ese directorio).
- `public/qrcodes/qr_10_store.png` aparece modificado en `git status`: cambio PREEXISTENTE de FASE 6A, no tocado por este lote.

---

# Adenda re-auditoría 2026-09-22 — Lote P1-1 + P2 (12/12 + bloqueante)

Tras la re-auditoría independiente que aprobó 12/12 puntos de 6B y dejó **P1-1 (bloqueante)** y P2 baratos. Lote mínimo, sin commit/push (pendiente autorización). Suite completa tras el lote: **110 passed (622 assertions)** 4.70 s · `php -l` 0 errores en 8 archivos · `pint --test` passed.

| # | Contrato | Archivo:línea | Comportamiento |
|---|---|---|---|
| P1-1 | TOCTOU en `publicCancel` | `app/Http/Controllers/Api/V1/OrderController.php:379-400` | Re-chequeo `if ($fresh->isPaid())` (422) dentro de la transacción tras `lockForUpdate()`, ANTES de `terminateShippingForCancelledOrder`/`CancelOrder`. Test `test_public_cancel_aborts_when_another_flow_marks_paid_without_restocking` (OrderCancellationTest): marca paid por "otro flujo" → 422, stock no repuesto, sin `restock_key`. |
| P2 | `confirmPayment` idempotente reconcilia legacy | `OrderController.php:486-494` | En la rama `isPaid()` idempotente, `order_state` se fija a `paid` SOLO si no está ya `paid`. Test `test_confirm_payment_reconciles_the_canonical_state_of_a_legacy_paid_order` (cart `status='3'` + `order_state=pending` → 200 y `paid`). |
| P2 | Asignación directa sin check de entrega activa | `app/Http/Controllers/Api/V1/DeliveryController.php:61-74` | Reusa patrón `otherActive` de `acceptOrder` (ShippingOrder `status IN ('1','2')` con `delivery=$deliveryId`) → 409 "El repartidor ya tiene una entrega activa." Test `test_direct_emission_rejects_a_rider_with_an_active_delivery` (sin mutaciones: sin 2º envio, carrito intacto). |
| P2 | Webhook MP resucitaba orden cancelada | `app/Http/Controllers/Api/V1/PaymentController.php:153-189` | Dentro del lock, `isCancelled()` → se aborta sin mutar y responde 200 `status=ignored`. HMAC y reconsulta sin cambios. Test `test_webhook_ignores_a_cancelled_order_without_mutating` (pago sigue 0, cart '2', orden sigue `cancelled`). |
| P2 | Backfill de roles 000002 | `database/migrations/2026_09_22_000002_backfill_delivery_roles.php:53-94` | (a) Guard `hasTable` extendido a `datospersonales`/`anexosdeliver` (test sin módulo delivery no rompe). (b) Se retiró el `whereExists(anexosdeliver)` del backfill `deliver` con la regla documentada: active + type '3' + verificado ⇒ rol deliver (el vínculo se valida en `eligibleDeliverer`, no en el backfill); test rider verificado sin vínculo recibe el rol. |

Archivos del lote: `app/Http/Controllers/Api/V1/OrderController.php`, `app/Http/Controllers/Api/V1/DeliveryController.php`, `app/Http/Controllers/Api/V1/PaymentController.php`, `database/migrations/2026_09_22_000002_backfill_delivery_roles.php`, `tests/Feature/OrderCancellationTest.php`, `tests/Feature/DeliverySecurityFixTest.php` (+1 test), `tests/Feature/PaymentSecurityTest.php` (+1 test), `tests/Feature/DeliveryMigrationFixTest.php` (+2 tests).

## Supuestos/advertencias de esta adenda (NO CONFIRMADO en producción)

- ✋ **Decisión de negocio asumida** (punto 5b): se retiró el `whereExists(anexosdeliver)` siguiendo la regla sugerida por el auditor (verificado + type '3' + activo ⇒ rol deliver). Implica que TODO rider verificado (aunque no tenga tienda vinculada) obtiene el rol `deliver`. No se confirmó aún con negocio; si se prefiere conservar el requisito de vínculo, restaurar el `whereExists` (el guard `hasTable('anexosdeliver')` ya existe para ello).
- El test P1-1 no puede reproducir la intercalación real de dos conexiones sobre SQLite `:memory:` (conexión única): fija el CONTRATO de salida del race (422 + stock intacto). La validación de concurrencia real con dos conexiones queda para un clon MariaDB desechable (fuera de este lote por regla "tests SOLO SQLite").

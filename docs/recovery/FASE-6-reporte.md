# FASE 6 — Auditoría maestra + P0 (reporte consolidado de lotes 6A, 6B y 6C)

Fecha: 2026-09-22 · Rama: `recuperacion` (HEAD `2985648`) · Sin commit ni push (pendiente autorización)
Stack de pruebas: PHP 8.2.12, Laravel 11.54.0, `php artisan test` sobre SQLite `:memory:` (forzado en `phpunit.xml`/`tests/TestCase.php`). No se ejecutó nada sobre producción ni MariaDB. Documento hermano con detalle de lote: `FASE-6B-reporte.md` (P1–P13 + adenda P1-1/P2); este reporte consolida FASE 6 completa (6A + 6B + 6C).

## Resultados

| Validación | Comando | Resultado |
|---|---|---|
| Suite completa (tras lote 6C) | `php artisan test` | **110 passed (622 assertions)** |
| Lotes 6B/6C (tests nuevos/adaptados) | `php artisan test --filter "DeliverySecurityFixTest\|DeliveryMigrationFixTest\|UnknownApiRouteTest\|DeliveryAuthorizationTest\|OrderCancellationTest\|PaymentSecurityTest"` | 51 passed |
| Sintaxis | `php -l` sobre los archivos del lote | 0 errores |
| Estilo | `php vendor/bin/pint --test` (focalizado, aplicado y revalidado) | passed |
| Suite JS (`npm test`, `npm run test:e2e`, `npm run build`, `npm audit`) | no ejecutada | sin cambios en `frontend/` en esta fase (scope backend) — reprogramar antes de FASE 7 |
| Artefactos | `public/uploads/.tmp-6b-*.png` | 0 restos (limpieza + `tearDown` defensivo) |

## Contratos cumplidos — Lote 6A (BASE de la auditoría P0)

| # | Contrato | Archivo:línea(s) clave | Comportamiento |
|---|---|---|---|
| 6A-1 | Tenant en emitOrder | `app/Http/Controllers/Api/V1/DeliveryController.php:24-106` | `Store::byOwner($user->name)->firstOrFail()` (L27) + `PurchaseOrder::where('serial', $store->serial)->lockForUpdate()` (L32-34). Pedido cancelado o con envío de distinta asignación → 409. Emisión idempotente; locks `PurchaseOrder` → `ShippingOrder`. |
| 6A-2 | RBAC Spatie por ruta | `routes/api.php:106-281` | Middleware `role_or_permission:...` por ruta/grupo: `products.*`, `pos.*`, permisos `delivery.manage`, `delivery.block`, `delivery.accept`, `delivery.complete`, `delivery.cancel`, `delivery.profile`, `delivery.wallet`, `delivery.location`, rol `deliver`, `dashboard.view`, etc. |
| 6A-3 | Verificación global solo superadmin; se elimina `verifyDeliver` del dueño | `routes/api.php:262-265`; diff vs HEAD elimina `delivery/linked/{link}/verify` | `adminGetDeliverer`/`adminToggleVerify` solo bajo `role_or_permission:super-admin`. El dueño conserva `linkedDeliverers` (ver) y `toggleBlock` (bloquear/desvincular para SU tienda), nunca concede `verificado` global. |
| 6A-4 | Ocultar `verification_code`/OTP y documentos | `DeliveryController.php:766-777` (`shippingData`), `:131-148` (`linkedDeliverers`), `:543-555` (`profile`), `:154-181` (`adminGetDeliverer`) | Las respuestas de emisión/aceptación/envíos NO serializan `verificacion.code` ni `fotoID`/`fotoDomicilio`. `VerificationCode` (`verificacion`) solo se borra en `AdminController::cleanData` (`AdminController.php:154`); no existe endpoint que devuelva el código al rider/owner. |
| 6A-5 | API desconocida → JSON 404 | `routes/web.php:7-12` + `:15-19` | `GET/POST api` y `api/` → 404 JSON; catch-all SPA excluye la raíz API con `^(?!api).*$`; SPA profunda sigue en 200. |
| 6A-6 | completeOrder fail-closed | `DeliveryController.php:391-402` + `config/delivery.php:15` | Con `DELIVERY_COMPLETION_ENABLED=false` (default) → 503; la barrera deja de depender de un comentario. |
| 6A-7 | Asignación pool/direct | `app/Http/Requests/Delivery/EmitShippingOrderRequest.php:17-24` + migración `2026_09_22_000001` | `delivery_id` presente ⇒ `direct`; ausente ⇒ `pool` (deprecación documentada, FASE 7 corrige UI). `direct` exige `eligibleDeliverer` (verificado + vínculo no bloqueado + rol). |

Tests que cubren 6A: `tests/Feature/DeliveryAuthorizationTest.php` (10 tests: `test_store_owner_cannot_globally_verify_a_deliverer`, `test_linked_deliverers_hide_identity_and_address_documents`, `test_cross_tenant_emission_is_not_found_and_does_not_mutate`, `test_direct_emission_rejects_an_ineligible_rider_without_side_effects`, `test_pool_order_can_only_be_accepted_by_an_eligible_linked_rider`, `test_sequential_pool_acceptance_has_one_winner_and_is_idempotent_for_that_rider`, `test_owner_and_rider_responses_never_expose_legacy_verification_codes`, `test_completion_is_fail_closed_and_does_not_mutate_wallet_or_order`, `test_direct_assignment_cannot_return_to_pool_but_accepted_pool_can`, `test_emission_is_idempotent_and_cancelled_orders_are_rejected`), `tests/Feature/UnknownApiRouteTest.php` (2 tests).

## Contratos cumplidos — Lote 6B (resumen; detalle en `FASE-6B-reporte.md`)

| # | Contrato | Estado |
|---|---|---|
| P1 | Evidencia fail-closed (`uploadEvidence`) + `completeOrder` 503 configurable | IMPLEMENTADO + PROBADO |
| P2 | Estado pago legacy (`cart.status='3'`) preservado en emit/accept/cancel | IMPLEMENTADO + PROBADO |
| P3 | GPS IDOR cerrado (rider solo propia; owner rol + vínculo + entrega activa) | IMPLEMENTADO + PROBADO |
| P4 | Cambio de documentos revoca verificación (`updateProfile`) | IMPLEMENTADO + PROBADO |
| P5 | Migración `assignment_mode` (backfill CASE, NOT NULL DEFAULT pool, únicos `ordenCompra`/`orderC` con preflight que aborta sin borrar) | IMPLEMENTADO + PROBADO |
| P6 | `confirmPayment` guardas (cancelada → 409; pagada → 200 idempotente) | IMPLEMENTADO + PROBADO |
| P7 | Backfill de roles `store-owner`/`deliver` + permisos `orders.*`/`delivery.*` idempotente | IMPLEMENTADO + PROBADO |
| P8 | Cancelación marca envío terminal `status='4'` y lo oculta de listados/aceptación | IMPLEMENTADO + PROBADO |
| P9 | Payload bundle legacy sin `assignment_mode` → inferencia pool/direct | IMPLEMENTADO + PROBADO |
| P10 | `SiteSetting::$hidden` incluye `mail_password` | IMPLEMENTADO + PROBADO |
| P11 | Doble aceptación → 409 | IMPLEMENTADO + PROBADO |
| P12 | `config/delivery.php` flag `completion_enabled=false` → 503 | IMPLEMENTADO + PROBADO |
| P13 | `/api` y `/api/` → 404 JSON + SPA profunda 200 | IMPLEMENTADO + PROBADO |

## Contratos cumplidos — Lote 6C (re-auditoría; adenda del 6B)

| # | Contrato | Archivo:línea | Comportamiento |
|---|---|---|---|
| P1-1 | TOCTOU en `publicCancel` | `app/Http/Controllers/Api/V1/OrderController.php:379-400` | Re-chequeo `if ($fresh->isPaid())` (422) dentro de la transacción tras `lockForUpdate()`, ANTES de `terminateShippingForCancelledOrder`/`CancelOrder`. Test: `OrderCancellationTest::test_public_cancel_aborts_when_another_flow_marks_paid_without_restocking` (marca paid por "otro flujo" → 422, stock no repuesto, sin `restock_key`). |
| P2 | `confirmPayment` reconciliación legacy | `OrderController.php:499-508` | En rama `isPaid()` idempotente, `order_state` se fija a `paid` solo si no está ya `paid` (cart `status='3'` con `order_state=pending` → 200 y `paid`). Test: `DeliverySecurityFixTest::test_confirm_payment_reconciles_the_canonical_state_of_a_legacy_paid_order`. |
| P2 | Emisión directa con entrega activa | `DeliveryController.php:60-69` | Reusa patrón `otherActive` de `acceptOrder` (ShippingOrder `status IN ('1','2')` con `delivery=$deliveryId`) → 409 "El repartidor ya tiene una entrega activa." Test: `test_direct_emission_rejects_a_rider_with_an_active_delivery` (sin mutaciones: sin 2º envío, carrito intacto). |
| P2 | Webhook MP no resucita canceladas | `app/Http/Controllers/Api/V1/PaymentController.php:155-164, 191-193` | Dentro del lock, `isCancelled()` → aborta sin mutar y responde 200 `status=ignored`. HMAC y reconsulta sin cambios. Test: `PaymentSecurityTest::test_webhook_ignores_a_cancelled_order_without_mutating`. |
| P2 | Backfill de roles 000002 | `database/migrations/2026_09_22_000002_backfill_delivery_roles.php:53-94` | (a) Guard `hasTable` extendido a `datospersonales`/`anexosdeliver` (test sin módulo delivery no rompe). (b) Se retiró el `whereExists(anexosdeliver)` del backfill `deliver`: activo + type '3' + `verificado='1'` ⇒ rol `deliver` (el vínculo se valida en `eligibleDeliverer`, no en el backfill). Tests: `DeliveryMigrationFixTest::test_roles_backfill_skips_gracefully_when_the_delivery_module_tables_are_absent` y `test_roles_backfill_grants_role_to_a_verified_rider_without_store_link`. |

## NO CONFIRMADO / PENDIENTE (explícito, sin prometer cierre)

- **Concurrencia real NO validada**: locks `FOR UPDATE`, deadlocks, doble aceptación simultánea y doble webhook no se probaron con dos conexiones (SQLite `:memory:` es monoconexión; tests secuenciales). Verificado en el entorno: **no hay MariaDB/MySQL 11.8 desechable ni Docker**. Pendiente: clon desechable (FASE 9/14). El test P1-1 fija solo el CONTRATO de salida del race (422 + stock intacto).
- **Migraciones 000001/000002 no ejecutadas contra BD real de producción ni clon**: el preflight de duplicados en `ordenenvio`/`imageevidence` aborta el despliegue por diseño si hay duplicados; falta reconciliación de datos previa (FASE 14).
- **Suite JS no ejecutada** (`npm test`, `npm run test:e2e`, `npm run build`, `npm audit`): scope backend; `frontend/` sin cambios en esta fase.
- **Bundle original `public/` sigue consumiendo contrato legacy** (`emitShipping({})`): la compatibilidad de emisión se infiere vía `EmitShippingOrderRequest::prepareForValidation()`; la UI fuente se corrige en FASE 7.
- **P2 registrados para FASE 8/9** (lista cerrada en `MATRIZ-BRECHAS.md`, tabla P2-01…P2-09): `availableOrders` PII sin paginación; `attachStore` acepta ID numérico además de serial; `updateProfile` acepta URLs de documentos sin validar storage; `myOrders` por `X-Cart-Token`; `QrController` open redirect (`custom_url`); toggles `adminToggleVerify`/`toggleBlock` no idempotentes; POST `/api/` en producción → 419 CSRF (las rutas 404 viven en grupo web); flag `completion_enabled` no implementa flujo OTP; índices únicos `datospersonales.idLog` y `anexosdeliver(deliveryMan,store)` pendientes.
- Flujo OTP de cliente (código seguro + expiración + intentos + uso único) sigue PENDIENTE de implementación (decisión aprobada en FASE 8/9, ver abajo); `completeOrder` permanece en 503 hasta entonces.

## Decisiones de negocio CONFIRMADAS (registradas como aprobadas)

| Decisión | Alcance/estado |
|---|---|
| Asignación híbrida **pool/direct** al emitir envío | Implementada en 6A/6B (`assignment_mode`); aprobada. |
| Cancelación B: órdenes pagadas → `refund_pending` + verificación física antes de reponer; no reponer stock en cancelaciones hasta FASE 8 | Aprobada; la reposición real se implementa en FASE 8. |
| Pago B+A: efectivo/transferencia como métodos manuales verificables por el dueño; impago únicamente COD | Aprobada; implementación en FASE 8. |
| Cliente B: checkout invitado + claim de orden con identidad verificada; OTP de 6 dígitos (ampliación de seguridad sobre el código de 3 dígitos documentado), vigencia 15 min, 5 intentos, bloqueo 15 min; el rider nunca ve el código | Aprobada; implementación en FASE 8/9. |
| Wallet A: acredita `totEnvio` UNA vez por entrega + movimientos compensatorios idempotentes (earn/reverse sin saldo negativo) | Aprobada; implementación en FASE 9. |

Supuestos documentados de 6B que siguen vigentes: `imageevidence` (schema legacy `orderC`, `img`) no registra autor → 409 fail-closed asumido; `foto_id` es el documento de identificación (no existe `licencia` separada); orden de locks `PurchaseOrder` → `ShippingOrder` (evitar deadlock) sin validar contra MariaDB real.

## Archivos tocados en FASE 6

Modificados (`git status` vs HEAD `2985648`): `app/Http/Controllers/Api/V1/DeliveryController.php`, `app/Http/Controllers/Api/V1/OrderController.php`, `app/Http/Controllers/Api/V1/PaymentController.php`, `app/Models/ShippingOrder.php` (constante `STATUS_CANCELLED='4'` + doc de estados), `app/Models/SiteSetting.php` (`$hidden` + `mail_password`), `routes/api.php` (RBAC por ruta; verificación solo superadmin), `routes/web.php` (404 JSON API), `tests/Feature/OrderCancellationTest.php`, `tests/Feature/PaymentSecurityTest.php`.
Nuevos: `config/delivery.php`, `database/migrations/2026_09_22_000001_add_assignment_mode_to_shipping_orders.php`, `database/migrations/2026_09_22_000002_backfill_delivery_roles.php`, `app/Http/Requests/Delivery/EmitShippingOrderRequest.php`, `tests/Feature/DeliverySecurityFixTest.php` (20 tests), `tests/Feature/DeliveryMigrationFixTest.php` (6 tests), `tests/Feature/UnknownApiRouteTest.php` (2 tests), `tests/Feature/DeliveryAuthorizationTest.php` (10 tests).
Nota: `public/qrcodes/qr_10_store.png` y `public/uploads/*` aparecen modificados en `git status`: cambios PREEXISTENTES de FASE 6A, NO tocados por estos lotes; `public/` sigue inmutable salvo lo ya presente antes de esta fase.

---

## Estado de evidencia FASE 6

Fecha: 2026-09-22 · Rama: `recuperacion` (HEAD `2985648`, cambios sin commit/push) · Suite: **110 tests / 622 assertions** (SQLite `:memory:`), Pint focalizado y `php -l` limpios.

- IMPLEMENTADO EN CÓDIGO y PROBADO LOCALMENTE: lotes 6A, 6B y 6C (ver tablas superiores).
- NO CONFIRMADO: concurrencia real (sin MariaDB 11.8/Docker en el entorno), migraciones sobre BD real/clon, suite JS, compatibilidad del bundle legacy (UI fuente se corrige en FASE 7).
- Nada desplegado en producción; sin commit ni push (pendiente autorización del dueño). Sin INSERT/UPDATE/DELETE/DDL en producción.

Firmado: `ruta-seguridad` · `ruta-qa` · `ruta-review` — 2026-09-22.
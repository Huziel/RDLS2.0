# Matriz de brechas — actualizar con evidencia real ANTES de programar

| ID | Módulo/flujo | Evidencia original | Ruta Vue actual | Endpoint Laravel | Rol/tenant | Estado código | Pruebas | Brecha | Riesgo | Fase |
|---|---|---|---|---|---|---|---|---|---|---|
| M-01 | Marketplace global | Entrada al market p1 | `/marketplace` (provisional al consultar) | INSPECCIONAR | público | PENDIENTE REVISION | no constatado | portada/buscador/multitienda | medio | 7 |
| D-01 | Verificación global repartidor (solo superadmin) | Word pedidos pp3-7 + confirmación explícita | `/dashboard/admin-users` (provisional al consultar) | `admin/deliverers/{user}/verify` en grupo `role_or_permission:super-admin` (`routes/api.php:262-265`); ruta del dueño `delivery/linked/{link}/verify` **ELIMINADA** (diff vs HEAD) | superadmin | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliveryAuthorizationTest (`test_store_owner_cannot_globally_verify_a_deliverer`) | brecha cerrada; `adminToggleVerify` no idempotente (P2); revocación automática al cambiar documentos en `updateProfile` (6B P4) | CRÍTICO | 6/9 |
| D-02 | Aceptación y código 3 dígitos | Word pedidos pp10–11 | `/delivery` (provisional al consultar) | `delivery/orders/{shipping}/accept` (`routes/api.php:157`) | rider verificado, vinculado | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliveryAuthorizationTest (aceptación pool solo elegible; winner secuencial idempotente), DeliverySecurityFixTest (doble aceptación 409) | aceptación cerrada (eligibilidad + `lockForUpdate` en transacción); flujo OTP **NO** implementado: `completeOrder` → 503 por flag; `verificacion.code` sin endpoint público | CRÍTICO | 6/9 |
| O-01 | Pedidos vendedor | Word pedidos pp1–2 | `/dashboard/orders` (provisional al consultar) | `orders`, `orders/{id}` | dueño tienda | API existe | no constatado | UI, pago manual, estados | alto | 8 |
| P-01 | Tienda pública | Word market pp1–4 | `/store/:serial/*` | rutas públicas | público | FASE 5 en código | FASE 5 reportada, verificar | paridad visual | medio | 7/14 |
| 6B-01 | Evidencia imagen + completar entrega | Lote 6B (P1/P12) | — | `delivery/evidence`, `delivery/{shipping}/complete` | rider asignado activo | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliverySecurityFixTest (20 tests), DeliveryAuthorizationTest | 503 por config; 404/409/422 fail-closed; evidencias `/uploads/` con MIME real; 409 idempotente si ya existe | crítico | 6 |
| 6B-02 | Cancelación → entrega terminal | P8 | — | `orders/{id}/cancel`, `publicCancel` | dueño/público | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | OrderCancellationTest (8 tests), DeliverySecurityFixTest, DeliveryAuthorizationTest | transacción + `ordenenvio.status='4'`; TOCTOU cerrado (re-check `isPaid` bajo lock); cancelada sin enviar a `availableOrders`/aceptación | alto | 6 |
| 6B-03 | Backfill migraciones entregas/roles | P5/P7 | — | migraciones `2026_09_22_000001/000002` | — | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliveryMigrationFixTest (6 tests) | preflight duplicados abortan sin borrar; **no ejecutadas** en BD real/clon (NO CONFIRMADO) | alto | 6 |
| 6A-01 | Tenant en emisión + idempotencia | P5/P10 | `/dashboard/orders` (provisional al consultar) | `orders/{order}/emit-shipping` (`DeliveryController.php:24-106`) | dueño tienda | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliveryAuthorizationTest (`test_cross_tenant_emission_is_not_found_and_does_not_mutate`, `test_emission_is_idempotent_and_cancelled_orders_are_rejected`) | `Store::byOwner` + `ordencompra.serial`; pedido cancelado/otra asignación → 409; locks PO→SO | crítico | 6 |
| 6A-02 | Ocultar verification_code/OTP y documentos | Word pedidos pp3-11 | — | `shippingData()` (`DeliveryController.php:766-777`), `linkedDeliverers()` (`:131-148`), `profile()`, `adminGetDeliverer()` | todos | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliveryAuthorizationTest (`test_owner_and_rider_responses_never_expose_legacy_verification_codes`, `test_linked_deliverers_hide_identity_and_address_documents`) | `verificacion.code` sin serializar en respuestas (solo se borra en `cleanData`); `foto_id`/`foto_domicilio` fuera de respuestas dueño/rider | crítico | 6 |
| 6A-03 | `/api` y `/api/*` → JSON 404 (no SPA) | P13 | — | `routes/web.php:7-12` (404 JSON) + `:19` (catch-all SPA excluye `api`) | público | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | UnknownApiRouteTest (2 tests, GET+POST raíz/trailing slash + SPA profunda 200) | se mantiene SPA profunda 200 para rutas no-API | alto | 6 |
| 6A-04 | completeOrder fail-closed 503 | P1/P12 | — | `completeOrder()` (`DeliveryController.php:391-402`) + `config/delivery.php:15` | rider | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliverySecurityFixTest (`test_completion_is_locked_by_config_flag`) | flag `completion_enabled=false` → 503; flujo OTP pendiente FASE 8/9 | crítico | 6/9 |
| 6A-05 | Asignación pool/direct | P5/P9 | — | `EmitShippingOrderRequest.php:17-24` (inferencia legacy); migración `2026_09_22_000001` | dueño | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliverySecurityFixTest (`test_bundle_payload_without_assignment_mode_is_inferred`), DeliveryMigrationFixTest | backfill CASE, `NOT NULL DEFAULT 'pool'`, únicos `ordenCompra`/`orderC` con preflight que aborta sin borrar | alto | 6 |
| 6C-01 | TOCTOU en `publicCancel` | re-auditoría P1-1 | — | `OrderController::publicCancel()` (`:379-400`) | público | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | OrderCancellationTest (`test_public_cancel_aborts_when_another_flow_marks_paid_without_restocking`) | re-check `isPaid` tras `lockForUpdate` ANTES de terminar envíos/repuestas; concurrencia real sin MariaDB (NO CONFIRMADO) | crítico | 6/9 |
| 6C-02 | `confirmPayment` reconcilia legacy + guardas | P2 | — | `OrderController::confirmPayment()` (`:486-528`) | dueño | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliverySecurityFixTest (`test_confirm_payment_reconciles_the_canonical_state_of_a_legacy_paid_order`), OrderCancellationTest | cancelada → 409; ya pagada → 200 idempotente y `order_state=paid` solo si no estaba | alto | 6 |
| 6C-03 | Emisión directa con entrega activa | P2 | — | `emitOrder()` (`DeliveryController.php:60-69`) | dueño | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliverySecurityFixTest (`test_direct_emission_rejects_a_rider_with_an_active_delivery`) | 409 sin mutaciones (sin 2º envío, carrito intacto) | alto | 6 |
| 6C-04 | Webhook MP ignora órdenes canceladas | P2 | — | `PaymentController` webhook (`:155-164`, `:191-193`) | MP | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | PaymentSecurityTest (`test_webhook_ignores_a_cancelled_order_without_mutating`) | 200 `ignored`, sin mutación, HMAC y reconsulta intactos | crítico | 6 |
| 6C-05 | Backfill de roles 000002 extendido | P7 | — | migración `2026_09_22_000002_backfill_delivery_roles.php:53-94` | — | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | DeliveryMigrationFixTest (+3: guard `hasTable` datospersonales/anexosdeliver; rider verificado sin vínculo recibe rol) | decisión asumida: el vínculo se valida en `eligibleDeliverer`, no en el backfill | medio | 6 |
| NC-01 | Concurrencia real (FOR UPDATE, deadlocks, doble aceptación/webhook simultáneos) | — | — | — | — | NO CONFIRMADO | tests secuenciales SQLite 1 conexión (no reproducen intercalación) | falta MariaDB 11.8/Docker desechable (entorno verificado SIN Docker); pendiente clon desechable | alto | 9 |
| NC-02 | Migraciones 000001/000002 contra BD real/clon | P5/P7 | — | migraciones | — | NO CONFIRMADO | solo SQLite `:memory:` | preflight de duplicados en `ordenenvio`/`imageevidence` aborta el despliegue por diseño; falta reconciliación previa | alto | 14 |
| NC-03 | Suite JS (`npm test`, `test:e2e`, `build`, `npm audit`) | — | todo frontend | — | — | NO CONFIRMADO | no ejecutada en FASE 6 (scope backend, sin cambios JS) | reprogramar antes de FASE 7 | medio | 7 |
| NC-04 | Bundle original `public/` vs contrato nuevo | P9 | `/delivery` (bundle legacy) | — | — | NO CONFIRMADO | compatibilidad de emisión inferida (`emitShipping({})` → pool) | la UI fuente se corrige en FASE 7 | medio | 7 |

**Estados permitidos:** ORIGINAL DOCUMENTADO, AMPLIACIÓN NUEVA, IMPLEMENTADO EN CÓDIGO, PROBADO LOCALMENTE, DESPLEGADO/VERIFICADO EN PRODUCCIÓN, NO CONFIRMADO. Sustituir cada `INSPECCIONAR` por ruta/archivo/línea real. Añadir una fila por cada pantalla/acción mencionada en la evidencia.

## P2 registrados para FASE 8/9 (lista cerrada, sin prometer cierre)

| ID | Hallazgo | Evidencia (archivo:línea) | Fase objetivo |
|---|---|---|---|
| P2-01 | `availableOrders` expone datos de cliente (PII) sin paginación | `DeliveryController.php:291` (`availableOrders`) | 8/9 |
| P2-02 | `attachStore` acepta ID numérico además de serial | `DeliveryController.php:236-242` | 8/9 |
| P2-03 | `updateProfile` acepta URLs de documentos sin validar storage | `DeliveryController.php:570-571` | 8/9 |
| P2-04 | `myOrders` autentica por `X-Cart-Token` (sin identidad verificada) | `OrderController.php:546-572` | 8 |
| P2-05 | `QrController` open redirect via `custom_url` | `QrController` (revisar ruta QR) | 11 |
| P2-06 | Toggles `adminToggleVerify`/`toggleBlock` no idempotentes | `DeliveryController.php:184-218` | 8/9 |
| P2-07 | POST `/api/` en producción → 419 CSRF (las rutas 404 viven en grupo web) | `routes/web.php:7-12` | 14 |
| P2-08 | Flag `delivery.completion_enabled` no implementa flujo OTP | `config/delivery.php:15` | 8/9 |
| P2-09 | Índice único pendiente: `datospersonales.idLog` y `anexosdeliver(deliveryMan,store)` | esquema legacy, revisar contra clon | 14 |

---

## Estado de evidencia FASE 6

Fecha: 2026-09-22 · Rama: `recuperacion` (HEAD `2985648`, cambios sin commit/push) · Suite: **110 tests / 622 assertions** (SQLite `:memory:`), Pint focalizado y `php -l` limpios.

- IMPLEMENTADO EN CÓDIGO y PROBADO LOCALMENTE: lotes 6A, 6B y 6C (detalle en `FASE-6-reporte.md`).
- NO CONFIRMADO: concurrencia real (sin MariaDB 11.8/Docker en el entorno), migraciones sobre BD real/clon, suite JS, compatibilidad del bundle legacy (UI fuente se corrige en FASE 7).
- Nada desplegado en producción; sin commit ni push (pendiente autorización del dueño).

Firmado: `ruta-seguridad` · `ruta-qa` · `ruta-review` — 2026-09-22.

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

---

## Estado de evidencia FASE 8A (validación final R3 + micro-ronda)

Fecha: 2026-09-23 · Rama: `recuperacion` · HEAD actual `943860a5d9772ce7eb133c91f81410a7729ef653` tras `reset --soft` del commit local no autorizado `8a43a72`; 8A + micro-fixes preservados en index/working tree, **SIN commit y SIN push**, pendientes de autorización explícita. Suite final: **158 tests / 938 assertions** (SQLite `:memory:`), focal 8A **30 tests / 165 assertions**, `php -l` 51 archivos OK, Pint focalizado passed y diff check limpio. Seguridad micro **GO** y review final **GO**, sin P0/P1 nuevos. Detalle en `FASE-8A-reporte.md`.

Nuevas filas cerradas en esta fase:

| ID | Módulo/flujo | Evidencia | Ruta/endpoint | Rol/tenant | Estado código | Pruebas | Brecha | Riesgo | Fase |
|---|---|---|---|---|---|---|---|---|---|
| 8A-01 | Idempotencia canónica de órdenes (pay/cancel/confirm/refund/return/proof/extra) | auditoría P0/P1 | `OrderIdempotency.php` + `order_audit_events` | todos | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | Fase8aCorrectiveTest (422 sin key en pay/cancel; 1 sola preference con 2 keys HTTP), OrderFinanceFlowTest | clave 1–100 chars; audit append-only con `request_hash`; conflicto mismo key/distinto contenido → 409 | crítico | 8 |
| 8A-02 | Webhook MP canónico (sin doble cobro, `amount_paid` monotónico) | P0/P1 | `PaymentController::webhook` | MP | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | PaymentSecurityTest (3 webhooks → 2 tx, paid=80, no duplica retry), Fase8aCorrectiveTest (overpay→exception, legacy_unknown con evidencia→paid sin duplicar, backfill re-notificación estable) | `paidCents = max(prev, acumulado)`; `order_provider_transactions` append-only único provider+payment_id; items status 5 intactos; throttling por `webhook_secret` + HMAC | crítico | 8 |
| 8A-03 | Conversión legacy `legacy_unknown` one-shot | P2 | `OrderController::confirmPayment` | dueño | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | Fase8aCorrectiveTest (cash+referencia → paid; sin method → 422; 2º intento otro método → 422) | one-shot; congelado `frozen_at`; refs cash/bank no re-canonizables | alto | 8 |
| 8A-04 | Financial chunk `clean-data` fail-closed intra-tx | P2 | `AdminController::cleanData` | superadmin puro | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | Fase8aCorrectiveTest (superadmin con filas financieras → 409; inyección vía `DB::listen` detectada DENTRO de la tx → 409 + atomicidad: 0 borrados, fila infiltrada rodó atrás) | doble check dentro de la transacción; super-admin dual-rol no muta finanzas de tienda | crítico | 8 |
| 8A-05 | Comprobantes de transferencia append-only + doble límite | P1/P2 | `OrderFinanceController::submitProof` + `throttle:proof-uploads` | cliente/dueño | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | Fase8aCorrectiveTest (5/min orden: 3×201, 4-5×422, 6×429; IP 20×404 + 1×429), OrderFinanceFlowTest (append-only, 3 archivos máx, no paga hasta confirmar, ejecutable 422) | 2 límites combinados (IP+serial+order 5/min y IP 20/min fail-closed); storage privado | alto | 8 |
| 8A-06 | Refund/return con permisos `orders.refunds.verify` / `orders.returns.verify` | P2 | `OrderFinanceController::refund/receiveReturn` + migración 000004 | dueño | IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE | Fase8aCorrectiveTest (403 sin permiso, 0 audit events), OrderFinanceFlowTest (refund ≤ amount_paid, return restockable/no independientes de refund) | 403 middleware antes de tocar la orden; `amount_refunded` ≤ `amount_paid` | alto | 8 |

### Cierres verificados de la micro-ronda

| ID | Cierre verificado | Evidencia (archivo/función) | Tests / review | Estado |
|---|---|---|---|---|
| 8A-P7 | `emitOrder` incorpora `denySuperAdminMutation`; superadmin puro/dual recibe 403 antes de mutar | `app/Http/Controllers/Api/V1/DeliveryController.php:25-35` (`emitOrder`) | `test_emit_shipping_is_denied_for_a_pure_superadmin`, `test_emit_shipping_denies_a_dual_role_superadmin_before_mutating`; seguridad GO + review GO | CERRADO: IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE |
| 8A-P8 | Paridad 422/502: `/pay` no-MP → 422 sin HTTP; owner `createPreference` con key vacía/ausente → 422; `PaymentPreferenceNotAllowed` separa reglas 422 de transporte/MP inválido 502 genérico con rollback | `OrderController::publicPaymentPreference()` (`app/Http/Controllers/Api/V1/OrderController.php:302-361`), `PaymentController::createPreference()` (`app/Http/Controllers/Api/V1/PaymentController.php:87-126`), `app/Exceptions/PaymentPreferenceNotAllowed.php:7-22`, `CreatePreference.php:38-63` | `test_public_pay_rejects_a_non_mp_order_without_calling_mercado_pago`, `test_store_preference_without_idempotency_key_is_422_not_502`, `test_public_pay_hides_transport_runtime_exception_and_rolls_back_snapshot`, `test_public_pay_maps_provider_500_and_malformed_response_to_generic_502`; seguridad GO + review GO | CERRADO: IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE |

También cerrado y verificado: mensaje dinámico de `publicCancel` según `restocked`/`return_pending` (`OrderController.php:385-426`), probado por `test_public_cancel_reports_the_restock_in_the_message` y `test_public_cancel_reports_return_pending_message_when_already_departed`.

Pendientes P2 registrados de 8A (NO bloqueantes del GO; deben resolverse antes del despliegue correspondiente):

| ID | Hallazgo | Evidencia (archivo:línea) | Fase objetivo |
|---|---|---|---|
| 8A-P1 | Sin throttle en `/pay`, `/cancel` y `payments/webhook` (solo `transfer-proof` lo tiene) | `routes/api.php:57,323,325` vs `:319-320` | 9 |
| 8A-P2 | Sin test para `Idempotency-Key` > 100 caracteres (validación existe en `OrderIdempotency::key()`) | `app/Services/OrderIdempotency.php:16-22` | 9 |
| 8A-P3 | Webhook posterior a `payment_exception` sin prueba (2 cargos cubiertos, 3º no) | `Fase8aCorrectiveTest.php:40-76` | 9 |
| 8A-P4 | `assertTrue(true)` en catch (débil por construcción) en test de dinero | `Fase8aCorrectiveTest.php:239,248` | 9 |
| 8A-P5 | **MariaDB 11.8/Docker desechable: deadlocks, locks FOR UPDATE, doble webhook/aceptación simultáneos, migración 000003/000004 sobre clon — NO CONFIRMADO** | entorno real sin Docker | 9/14 |
| 8A-P6 | Deferral UI del contrato estricto: `CheckoutView.vue` (frontend/src y bundle `public/`) NO envía `payment_method`; `payOrder`/`cancelOrder` NO envían `Idempotency-Key`. Backend ya devuelve 422 limpio (no 502), por lo que la UI sigue incompatible: checkout 422 y pay/cancel 422 hasta el lote UI. El backend estricto NO puede desplegarse separado. `legacy_unknown` queda inalcanzable desde checkout nuevo | `app/Http/Requests/Order/CheckoutRequest.php:27`, `frontend/src/views/public/store/CheckoutView.vue:84-95`, `frontend/src/api/public-store.js:35-38`; backend: `test_public_pay_without_idempotency_key_is_422_not_502`, `test_public_cancel_without_idempotency_key_is_422` | 7 (UI) / antes del despliegue conjunto |
| 8A-P9 | `publicOrderDetail` es token-only y no recibe/valida serial; resolver contrato backend + frontend atómicamente | `routes/api.php:331`; `OrderController::publicOrderDetail()` (`app/Http/Controllers/Api/V1/OrderController.php:247-289`); `frontend/src/api/public-store.js:31-32`; tests actuales solo cubren token: `CheckoutInventoryTest::test_public_order_detail_requires_the_cart_token_that_created_it`, `Fase8aCorrectiveTest::test_public_order_detail_is_scoped_to_the_cart_token` | 7/9 / antes del despliegue conjunto |
| 8A-P11 | Pint global reporta 26 archivos legacy preexistentes fuera del lote; Pint focalizado de los 51 archivos 8A pasa | auditoría final del orquestador: `vendor/bin/pint --test` global vs focalizado | mantenimiento / riesgo bajo |
| 8A-P12 | Semántica dual de `cart.status=5`: despacho lo asigna; webhook lo interpreta/preserva como devuelto | `DeliveryController::dispatchOrder()` (`app/Http/Controllers/Api/V1/DeliveryController.php:420-427`) vs `PaymentController::webhook()` (`app/Http/Controllers/Api/V1/PaymentController.php:298-303`); `test_webhook_accepts_a_legacy_unknown_order_with_mp_evidence_and_skips_returned_items` no resuelve el contrato | 9 |
| 8A-P13 | Subpago MP queda `pending` y webhook responde `ok` sin señal operativa específica | `PaymentController::webhook()` (`app/Http/Controllers/Api/V1/PaymentController.php:267-288,338`); `PaymentSecurityTest::test_webhook_preserves_a_partial_charge_without_marking_the_order_paid` | 9 |

`8A-P10` no se duplica: el pendiente MariaDB/InnoDB ya está registrado como `8A-P5`.

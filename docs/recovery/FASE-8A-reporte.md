# FASE 8A — Reporte de validación final (Ronda 3 + micro-ronda de cierre)

Fecha: 2026-09-23 · Rama: `recuperacion` · HEAD actual: `943860a5d9772ce7eb133c91f81410a7729ef653` (Fase 6). El commit local no autorizado `8a43a72` fue deshecho mediante `reset --soft` sin pérdida y ya no es HEAD; todo 8A y los micro-fixes permanecen en index/working tree, **SIN commit y SIN push**, pendientes de autorización explícita.

## Veredicto: GO final (con pendientes P2 registrados)

Suite completa, focal 8A, `php -l`, Pint focalizado y diff check en verde. Seguridad micro: **GO**. Revisión final: **GO**, sin P0/P1 nuevos. Estado documental: IMPLEMENTADO EN CÓDIGO dentro de index/working tree y PROBADO LOCALMENTE; no existe commit 8A actual ni push.

## Entorno y aislamiento

- PHP 8.2.12 (ZTS VS2019 x64), Laravel Framework 11.54.0, `pdo_sqlite` presente.
- `phpunit.xml` fuerza `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` con `force="true"` y `DB_URL=""`.
- `tests/TestCase.php:16-18` aborta con `RuntimeException` si el driver no es `sqlite :memory:` (guard verificado).
- Ninguna conexión a BD remota ni llamada HTTP real (MP/otros mockeados vía `Http::fake`). Validación 100% local.

## Comandos ejecutados (resultados reales)

| Comando | Resultado |
|---|---|
| `php artisan test --do-not-cache-result` | **158 passed, 938 assertions** — 0 fallos |
| `php artisan test --do-not-cache-result tests/Feature/Fase8aCorrectiveTest.php` | **30 passed, 165 assertions** — 0 fallos |
| `php -l` sobre los 51 archivos PHP 8A (modificados + nuevos) | **51 archivos OK, 0 fallos** |
| `vendor/bin/pint --test <51 archivos 8A>` | **passed** (exit 0) |
| `git diff --check` / `git diff --cached --check` | **limpio** (exit 0) |

## Confirmación de escenarios con conteos reales

| Escenario requerido | Test que lo prueba | Resultado verificado |
|---|---|---|
| `/pay` sin `Idempotency-Key` ⇒ **422 no 502** | `Fase8aCorrectiveTest::test_public_pay_without_idempotency_key_is_422_not_502` | PASS: `assertStatus(422)`, mensaje exacto, `Http::assertNothingSent()` (sin 502 desde MP) |
| `/cancel` sin `Idempotency-Key` ⇒ **422 no 502** | `test_public_cancel_without_idempotency_key_is_422` | PASS: 422 + mensaje exacto |
| Webhook `legacy_unknown` con evidencia ⇒ **paid sin duplicar** | `test_webhook_accepts_a_legacy_unknown_order_with_mp_evidence_and_skips_returned_items` | PASS: `order_payments` → method `mercado_pago`, status `paid`, provider `5001`; 1 tx en `order_provider_transactions`; `mercadopago.status=1`; orden `STATE_PAID` |
| Re-notificación backfill estable (sin duplicado) | `test_webhook_re_notification_of_a_backfilled_payment_id_canonicalizes_and_does_not_duplicate` | PASS: `assertDatabaseCount('order_provider_transactions', 1)`; `frozen_at` NOT NULL; orden permanece `pending`; 0 audit events |
| `paidCents` max monotónico con **3 webhooks** | `PaymentSecurityTest::test_webhook_requires_a_valid_signature_and_verifies_the_remote_payment` (30 + 50 + retry 30) | PASS: `order_provider_transactions` count **2**; `amount_paid` = **80.00** (ni 110 por duplicado, ni 60 por clobber) |
| Conversión legacy one-shot: cash sin method ⇒ **422** | `test_confirm_payment_rejects_legacy_unknown_without_a_declared_method` | PASS: 422 mensaje exacto |
| Conversión legacy one-shot: proof transfer correcta | `test_confirm_payment_converts_legacy_unknown_to_cash_with_reference_and_locks_it_one_shot` + `OrderFinanceFlowTest::test_transfer_proof_is_private_append_only_and_does_not_pay_until_owner_confirms` | PASS: cash+reference → paid, 2º intento con otro método 422 (`El metodo de cobro no puede cambiarse.`); proof append-only (1 fila, re-submit 409), no paga hasta confirmación del dueño |
| `cleanData` guard intra-tx con `DB::listen` ⇒ **409 + atomicidad** | `test_clean_data_revalidates_finance_protection_inside_the_transaction` | PASS: 409 + mensaje; `liks` intacto; `assertDatabaseCount('order_payments', 1)` (fila infiltrada rodó atrás con la transacción) |
| Throttle IP **20+1 ⇒ 429** | `test_proof_uploads_are_rate_limited_aggregate_by_ip` | PASS: intentos 1–20 con órdenes rotadas → 404; intento 21 → 429 |
| Throttle **5/min por orden** (1–3 ⇒ 201, 4–5 ⇒ 422, 6 ⇒ 429) | `test_proof_uploads_are_rate_limited_after_five_attempts` | PASS: `order_payment_proofs` count **3**; 6º intento 429. Doble capa verificada en `AppServiceProvider::boot` (`proof-uploads`: 5/min IP+serial+order + 20/min IP) |
| **403** sin permiso `orders.refunds.verify` | `test_refund_requires_the_verify_permission` | PASS: `assertForbidden()`, `assertDatabaseCount('order_audit_events', 0)` |
| Items **status 5 intactos** tras webhook | `test_webhook_accepts_a_legacy_unknown_order_with_mp_evidence_and_skips_returned_items` | PASS: `cart` product 1 `status=5` intacto; product 2 → `status=3` |
| `/pay` para orden no-MP ⇒ **422 sin HTTP externo** | `test_public_pay_rejects_a_non_mp_order_without_calling_mercado_pago` | PASS: 422, mensaje exacto y `Http::assertNothingSent()`; guarda en `OrderController::publicPaymentPreference()` (`app/Http/Controllers/Api/V1/OrderController.php:302-319`) |
| Preferencia owner con `Idempotency-Key` vacío/ausente ⇒ **422 no 502** | `test_store_preference_without_idempotency_key_is_422_not_502` | PASS: 422, mensaje exacto y `Http::assertNothingSent()`; `PaymentController::createPreference()` captura `ValidationException` (`app/Http/Controllers/Api/V1/PaymentController.php:87-126`) |
| Reglas de preferencia ⇒ 422; transporte/MP inválido ⇒ **502 genérico + rollback** | `test_public_pay_maps_locked_order_state_rule_to_422`, `test_public_pay_hides_transport_runtime_exception_and_rolls_back_snapshot`, `test_public_pay_maps_provider_500_and_malformed_response_to_generic_502` | PASS: `PaymentPreferenceNotAllowed` separa reglas (`app/Exceptions/PaymentPreferenceNotAllowed.php:7-22`; `CreatePreference.php:38-63`) y no filtra secretos ni persiste snapshot/auditoría ante 502 |
| `emitOrder` niega superadmin puro y dual **antes de mutar** | `test_emit_shipping_is_denied_for_a_pure_superadmin`, `test_emit_shipping_denies_a_dual_role_superadmin_before_mutating` | PASS: 403; 0 `ordenenvio`; 0 audit events; guarda en `DeliveryController::emitOrder()` (`app/Http/Controllers/Api/V1/DeliveryController.php:25-35`) |
| `publicCancel` emite mensaje según resultado real | `test_public_cancel_reports_the_restock_in_the_message`, `test_public_cancel_reports_return_pending_message_when_already_departed` | PASS: mensaje de reposición solo si `restocked=true`; mensaje de retorno si `return_pending=true` (`OrderController::publicCancel()`: `app/Http/Controllers/Api/V1/OrderController.php:385-426`) |

## Pendientes P2 no bloqueantes de auditoría final

Seguridad micro y revisión final no encontraron P0/P1 nuevos. Quedan registrados estos P2/higiene, sin confundirlos con cierres verificados:

1. **Throttles públicos**: `/pay`, `/cancel` y `payments/webhook` no tienen throttle (`routes/api.php:57,323,325`); solo `transfer-proof` usa `throttle:proof-uploads` (`:319-320`). No existe test de límite para esos tres endpoints.
2. **`Idempotency-Key` > 100 caracteres**: `OrderIdempotency::key()` (`app/Services/OrderIdempotency.php:16-22`) devuelve 422, pero solo se probaron ausencia/vacío (`Fase8aCorrectiveTest::test_public_pay_without_idempotency_key_is_422_not_502`, `test_store_preference_without_idempotency_key_is_422_not_502`, `test_public_cancel_without_idempotency_key_is_422`).
3. **Tercer cargo posterior a `payment_exception`**: el segundo cargo/overpay está cubierto por `test_webhook_second_charge_on_an_active_order_marks_payment_exception` (`Fase8aCorrectiveTest.php:40-76`), no un webhook adicional después de ese estado.
4. **Semántica dual de `cart.status=5`**: `DeliveryController::dispatchOrder()` lo usa al despachar (`app/Http/Controllers/Api/V1/DeliveryController.php:420-427`), mientras `PaymentController::webhook()` lo preserva como renglón devuelto (`app/Http/Controllers/Api/V1/PaymentController.php:298-303`). El test `test_webhook_accepts_a_legacy_unknown_order_with_mp_evidence_and_skips_returned_items` prueba preservación, no resuelve el contrato semántico.
5. **Subpago MP silencioso**: `PaymentController::webhook()` mantiene `pending` cuando `paidCents < dueCents` y responde `ok` (`app/Http/Controllers/Api/V1/PaymentController.php:267-288,338`); `PaymentSecurityTest::test_webhook_preserves_a_partial_charge_without_marking_the_order_paid` verifica la persistencia, pero falta política/señal operativa específica.
6. **`publicOrderDetail` token-only sin serial**: ruta `public/orders/{id}` (`routes/api.php:331`) y `OrderController::publicOrderDetail()` filtran por `session` pero no por tienda (`app/Http/Controllers/Api/V1/OrderController.php:247-289`); frontend consume el contrato sin serial (`frontend/src/api/public-store.js:31-32`, `ThankYouView.vue:73-75`). Resolver backend y frontend atómicamente; los tests actuales solo verifican el token (`CheckoutInventoryTest::test_public_order_detail_requires_the_cart_token_that_created_it`, `Fase8aCorrectiveTest::test_public_order_detail_is_scoped_to_the_cart_token`).
7. **Conflicto de idempotencia mismo key + contenido distinto**: cubierto por status 409 en re-submit de proof (`OrderFinanceFlowTest`), sin aserción del mensaje `IdempotencyConflict`.
8. **Higiene de tests**: `assertTrue(true)` x2 en `Fase8aCorrectiveTest.php:239,248` y boilerplate preexistente en `tests/Unit/ExampleTest.php:14`; no invalidan la ruta de excepción porque existe `$this->fail()` previo, pero inflan assertions.

## No ejecutado (y por qué)

- **MariaDB real/clon desechable**: entorno sin Docker; SQLite `:memory:` de 1 conexión no reproduce intercalación/deadlocks (`NC-01`). **Pendiente explícito de 8A.**
- **Migraciones 8A contra BD real/clon** (`2026_09_22_000003/000004`): solo `:memory:`; preflight de duplicados valida localmente (`NC-02`).
- **Suite frontend** (`npm test`, `test:e2e`, `build`, `npm audit`): sin cambios JS en 8A; corresponde a ronda JS/Fase 7 (`NC-03`).
- **Playwright/Vitest** fuera del alcance de esta ronda.

### Calidad global fuera del lote

- **Pint global**: auditoría del orquestador reportó 26 archivos legacy preexistentes fuera del lote; riesgo bajo/P11. Pint focalizado sobre los 51 archivos 8A sí pasó.

## Artefactos

- `tests/Feature/Fase8aCorrectiveTest.php` (30 tests) — nuevo.
- `tests/Feature/OrderFinanceFlowTest.php`, `tests/Feature/OrderSecurityClosureTest.php`, `tests/Feature/CanonicalOrderMigrationTest.php` — nuevos.
- Modificados: `PaymentSecurityTest`, `OnlinePaymentPreferenceTest`, `OrderCancellationTest`, `DeliverySecurityFixTest`, `DeliveryAuthorizationTest`, `CheckoutInventoryTest`, `InteractsWithSalesSchema/InventorySchema`.
- App 8A: `OrderFinanceController`, `OrderIdempotency`, `CanonicalOrderAmount`, `PaymentPreferenceNotAllowed`, `OrderPayment(+Proof/Return/ProviderTransaction/AuditEvent)`, migraciones 000003/000004, modificados `OrderController`, `PaymentController`, `AdminController`, `CancelOrder`, `Checkout`, `CreatePreference`, `DeliveryController`, `UploadController`, `PurchaseOrder`, `ShippingOrder`, `routes/api.php`, `RoleAndPermissionSeeder`, `AppServiceProvider`, etc.

## Micro-ronda de cierre (2026-09-23)

Fixes IMPLEMENTADOS EN CÓDIGO en index/working tree y PROBADOS LOCALMENTE por el orquestador. Seguridad micro: **GO**. Review final: **GO**, sin P0/P1 nuevos:

1. `OrderController::publicPaymentPreference`: orden no-MP y `ValidationException` → 422 accionable; no-MP no hace HTTP externo. Transporte, respuesta MP inválida o fallo inesperado → 502 genérico con rollback. Tests: `test_public_pay_rejects_a_non_mp_order_without_calling_mercado_pago`, `test_public_pay_without_idempotency_key_is_422_not_502`, `test_public_pay_hides_transport_runtime_exception_and_rolls_back_snapshot`, `test_public_pay_maps_provider_500_and_malformed_response_to_generic_502`.
2. `PaymentController::createPreference`: `Idempotency-Key` vacío/ausente → 422, no 502. Test: `test_store_preference_without_idempotency_key_is_422_not_502`.
3. `DeliveryController::emitOrder`: `denySuperAdminMutation` devuelve 403 a superadmin puro/dual antes de mutar. Tests: `test_emit_shipping_is_denied_for_a_pure_superadmin`, `test_emit_shipping_denies_a_dual_role_superadmin_before_mutating`.
4. `OrderController::publicCancel`: mensaje dinámico según `restocked`/`return_pending`. Tests: `test_public_cancel_reports_the_restock_in_the_message`, `test_public_cancel_reports_return_pending_message_when_already_departed`.
5. Nueva `PaymentPreferenceNotAllowed`: separa reglas de negocio 422 de fallos de transporte/proveedor 502 genéricos; `CreatePreference` mantiene la transacción y los tests de transporte/proveedor verifican rollback de snapshot/auditoría.

Estado Git final: el commit local no autorizado `8a43a72` fue deshecho con `reset --soft` y ya no existe como HEAD. HEAD es `943860a5d9772ce7eb133c91f81410a7729ef653`; 8A y los micro-fixes siguen preservados en index/working tree, **sin commit y sin push**, pendientes de autorización explícita. Frontend checkout (`payment_method` + `Idempotency-Key`) queda como **8A-P6**: la UI fuente/bundle sigue incompatible y el backend estricto no puede desplegarse separado del lote UI.

## Resultados independientes

- `ruta-seguridad`: **GO** de micro-seguridad — micro-fixes verificados; sin P0/P1 nuevos.
- `ruta-qa`: **158 passed / 938 assertions**; focal `Fase8aCorrectiveTest` **30 / 165** — resultados verificados por el orquestador.
- `ruta-review`: **GO final** — separación 422/502, rollback, guard de superadmin y mensaje dinámico revisados; sin P0/P1 nuevos.

Fecha de cierre documental: 2026-09-23. Estado Git: **sin commit 8A actual y sin push**.

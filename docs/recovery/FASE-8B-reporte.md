# FASE 8B — reporte

Fecha: 2026-09-26 · Rama: `recuperacion` · base auditada `8fd678d` (== `origin/recuperacion` al iniciar). FASE 8B integrada en este commit local; **sin push**. Fuente 8B-S + 8B UI integrada; **NO desplegable todavía** por bundle `public/` legacy y gates MariaDB pendientes.

## Alcance

1. **8B-S backend**: cierre D1 (tienda pública sin PII) y D2 (pago cero terminal / `payment_exception` no cancelable), invariantes de orden/nivel transaccional bajo SQLite y lectura de readiness de pago bajo la transacción que bloquea la fila de tienda. **No son pruebas de concurrencia real**.
2. **8B UI frontend**: paridad total con el contrato estricto (rutas tenant, `payment_method`, `Idempotency-Key` determinista, capability header + relock, ticket nuevo con estados y QR local sin folio).

## Decisiones aplicadas (confirmadas por usuario)

| ID | Decisión |
|---|---|
| D1 | `publicShow` expone SOLO campos comerciales explícitos (sin `extra`, `createdby`, `phone`, `adress`, `lat/long`) |
| D2 | Pago cero terminal; `refund_pending`/`refunded`/`payment_exception` bloquean la cancelación pública; `payment_exception` NO = fondos cobrados (`amount_paid>0` rastrea lo real) |
| D3 | Chat público 503 fail-closed hasta FASE 12 |
| D4 | Runbook MariaDB desechable documentado; sin ejecución de DDL reales |
| D5 | 401 de rutas públicas NO expulsa sesión owner (interceptor acotado) |

## Backend — cambios verificables

| Archivo | Cambio | Prueba clave |
|---|---|---|
| `app/Models/OrderPayment.php` | `COLLECTED_STATUSES = [paid, refund_pending, refunded]`; `representsCollectedFunds()` | — |
| `app/Models/PurchaseOrder.php` | `isPaid()` usa COLLECTED + fallback legacy, excepto `payment_exception` (siempre false); `blocksCustomerCancellation()` bloquea la excepción | — |
| `app/Actions/Order/CancelOrder.php` | gate cliente → `blocksCustomerCancellation`; `$fundsTracked` = isPaid ∨ amount_paid>0 | exception no-cancel sin claiming fondos |
| `app/Actions/Order/ConfirmManualPayment.php` | `payment_exception`/refund terminal no se sobrescriben; `paid` cero es idempotente tras validar one-shot de método | exception + marcadores legacy no se confirma/cancela como cobro |
| `app/Http/Controllers/Api/V1/OrderController.php` | `publicCancel` usa gates; `publicPaymentPreference` guard 422 explícito para exception | idem |
| `app/Http/Resources/PublicOrderProjection.php` | `can_cancel` excluye payment_exception/proof_submitted/refund_pending | idem |
| `app/Http/Resources/PublicStoreResource.php` (NUEVO) | D1: `id, serial, category, logo, logojpg, name` (`safeName` strip_tags de `extra.nombreTienda`) | profile mínimo sin PII |
| `app/Http/Controllers/Api/V1/Store/StoreController.php` | `publicShow` → `PublicStoreResource::make`; theme resuelve serial case-insensitive de forma única | idem |
| `RequireStoreCatalogCapability.php` | resuelve serial case-insensitive y canoniza el parámetro antes del controlador | perfil protegido con casing alterno |
| `tests/Feature/Fase8bSecurityClosureTest.php` | exception, perfil, orden transaccional, readiness, conversión legacy terminal, casing integral e IDs inválidos | 18 tests / 190 assertions |

Nota crítica de portabilidad: SQLite no emite `FOR UPDATE` en SQL; `DB::transactionLevel() >= 1` demuestra contexto/orden transaccional (las consultas del controlador corren dentro de `DB::transaction`; throttle y setUp a nivel 0), **no exclusión mutua**. El gate real queda en `MARIADB-RUNBOOK.md`.

## Frontend — cambios verificables

| Archivo | Cambio |
|---|---|
| `utils/public-store.js` | capability per-store con serial canonizado en minúsculas, `capabilityHeaders`, doble FNV-1a estable, `checkoutIdempotencyKey` (serial+token+items+payload completo), `orderIdempotencyKey` |
| `api/public-store.js` | rutas tenant: `unlock`, `product(serial,id)`, `productAddons`, `orderDetail(serial,ref)`; capability header en catálogo/carrito/checkout; keys deterministas en pay/cancel; eliminados `verifyPassword`, `/public/products/:id`, `/public/orders/:ref`, `cloneIdempotencyKey` |
| `api/client.js` | 401 global solo para rutas no públicas (`/public/`,`/stores/`,`/qr/`,`/pages/`); sesión owner intacta |
| `StoreHomeView.vue` | flujo unlock con capability de sesión, relock 401/403 (limpia capability y vuelve al gate), storeName desde `theme.extra.nombre_tienda`/`store.name` |
| `ProductDetailView.vue` | `product(serial,id)`; relock 401/403 → home |
| `CartView.vue` | relock 401/403 en load y mutaciones |
| `CheckoutView.vue` | selector desde readiness real; costo local = máximo de `base/medio/largo` (misma regla backend, incluyendo el campo real `base`); fail-closed; COD solo shipping; `<form>`; key completa; relock |
| `ThankYouView.vue` | ticket nuevo, sin `telefono`, exception, paneles por método, cancelación muestra el mensaje real de restock/retorno, QR local `qrcode` sin folio → `/store/{serial}` |
| `package.json` | +`qrcode@^1.5.4` (0 vulnerabilidades tras install) |
| `tests/unit/public-store.test.js` | contrato nuevo: capability per-store, keys deterministas/estables, rutas tenant |
| `tests/e2e/public-store.spec.js` | rutas tenant, métodos/keys, costo `base`, readiness fail-closed, COD condicional, restock vs retorno, relock 401 sin logout owner, exception, QR data-URL; `site-settings`/`user` interceptados |

## Pruebas ejecutadas (reales, SQLite :memory: y mocks)

| Suite | Resultado |
|---|---|
| `php artisan test` (raíz) | **185 passed / 1229 assertions** (14.26s) |
| `Fase8bSecurityClosureTest` | **18 passed / 190 assertions** |
| Pint focalizado (11 archivos 8B) | passed |
| `git diff --check` | limpio |
| `npm test` (Vitest) | **40 passed** (6 archivos) |
| `npm run test:e2e` (Playwright) | **82/82** (desktop+mobile; tienda pública 26/26) |
| `npm run build` | OK, 187 módulos |
| `npm audit` | 0 vulnerabilidades |

## Riesgos y NO CONFIRMADO

- Concurrencia real MariaDB/InnoDB (locks FOR UPDATE explícitos, deadlocks, doble unlock/webhook simultáneo): NO CONFIRMADO — ver `MARIADB-RUNBOOK.md` para el clon desechable y `NC-01`/`8A-P5`/`8B-P1`.
- Migraciones `000001`/`000002` (payment settings, catálogo) solo validadas en SQLite; pendientes de clon.
- E2E corre sobre Vite dev server (`--mode test`), no sobre `dist/` ni CI.
- **Bloqueo de despliegue:** Laravel sirve el bundle histórico de `public/`, que aún llama rutas legacy y QR externo con folio. `public/` es original inmutable; se requiere proceso aprobado para ensamblar/publicar el nuevo artefacto y smoke E2E sobre ese artefacto.
- `X-Cart-Token` sigue siendo una sesión de carrito persistente, no identidad de cliente expirable. Diseñar claim/expiración requiere decisión de arquitectura y no se improvisó en 8B.
- `publicTheme` expone titulares/cuentas de transferencia y ubicación a tiendas abiertas; confirmar allowlist comercial antes de despliegue.
- `submitProof` y su bucket de throttle aún usan el serial crudo; con collation case-insensitive funciona, pero el casing puede fragmentar el bucket por orden. No bloquea 8B (la UI actual remite comprobante por WhatsApp); queda P2.
- QR sin folio (decisión 8B-P2): apunta a la página pública de la tienda; validar con el dueño antes de despliegue.
- Nada se ejecutó contra producción ni hosts remotos; ninguna migración/seed/restore; `public/`, `public/uploads`, `public/qrcodes` intactos.

Firmas independientes finales: **`ruta-review` GO fuente**, **`ruta-qa` GO fuente**, **`ruta-seguridad` GO fuente**; cero P0/P1 abiertos en el lote. Las revisiones iniciales detectaron y forzaron cierres D2, costo `base`, readiness, mensaje de restock, relock/reload, referencia legacy terminal y casing integral. **Despliegue: NO-GO** por bundle `public/` legacy, MariaDB no ejecutado, identidad cliente y allowlist de `publicTheme`.

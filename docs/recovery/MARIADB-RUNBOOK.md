# Runbook MariaDB/InnoDB desechable — FASE 8B

Estado: **DOCUMENTACION; NO EJECUTADO**. Este archivo no acredita migracion, concurrencia ni despliegue real.

## Objetivo

Validar, en una base MariaDB local y descartable, los DDL/indices y la exclusion mutua que SQLite `:memory:` no puede demostrar: `SELECT ... FOR UPDATE`, deadlocks, doble unlock, checkout/cancelacion simultaneos y webhooks concurrentes.

## Prohibiciones

- Nunca usar host remoto, produccion, staging compartido ni un dump con datos personales sin sanitizar.
- Nunca ejecutar `migrate:fresh`, `db:wipe`, rollback/reset/refresh, INSERT/UPDATE/DELETE/DDL o restore contra produccion.
- No copiar `.env`, credenciales, documentos, tokens, cuentas bancarias ni datos de clientes al reporte.
- El guard de Artisan NO protege SQL manual ni otros clientes: la seguridad principal es aislamiento de red + contenedor/base desechable.

## Precondiciones obligatorias

1. Motor local MariaDB 11.x/InnoDB disponible (Docker/Podman o VM descartable). En la auditoria 8B actual **NO estaba disponible**, por eso el gate queda NO CONFIRMADO.
2. Bind exclusivo a `127.0.0.1` en un puerto no estandar (ejemplo `33079`), sin exposicion LAN.
3. Nombre de base con prefijo inequívoco, por ejemplo `rdls_recovery_disposable_8b_YYYYMMDD`.
4. Usuario nuevo limitado a ESA base. Nunca reutilizar usuario de produccion.
5. `APP_ENV=testing`, `DB_CONNECTION=mysql`, host `127.0.0.1` y nombre desechable verificados visualmente antes de cualquier comando.
6. Artefactos de prueba sintéticos; si se necesita forma legacy, recrearla con fixtures sanitizados, no con restore de produccion.

## Arranque sugerido (operador humano)

El operador puede crear un contenedor efimero con MariaDB y volumen temporal. Las contraseñas deben inyectarse desde el entorno local y no escribirse en Git ni en este documento. Antes de continuar debe registrar solamente:

- version exacta de MariaDB;
- host `127.0.0.1`, puerto local y nombre de base desechable;
- SHA de Git y diff revisado;
- confirmacion de que no existe ruta de red a la BD remota.

No ejecutar los pasos siguientes hasta que `SELECT DATABASE(), @@hostname, VERSION()` confirme el destino descartable.

## Guard de comandos destructivos

El proyecto bloquea `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` y `migrate:rollback`. Si un clon descartable exige alguno, el error muestra el fingerprint exacto con formato:

```text
<connection>:<driver>:<host>:<port>/<database>
```

Solo en ese clon se permite establecer temporalmente:

```text
ALLOW_DESTRUCTIVE_DB_COMMANDS=true
DESTRUCTIVE_DB_CONFIRMATION=<fingerprint exacto mostrado por el guard>
```

Si el fingerprint no contiene `127.0.0.1`, el puerto local esperado y el prefijo `rdls_recovery_disposable_`, **ABORTAR**. Para una base nueva vacia debe preferirse `php artisan migrate --force`, que no requiere destruir nada.

## Gates DDL

1. Ejecutar las migraciones en una base vacia y registrar tiempo/resultado.
2. Repetir `migrate --force`: debe ser retryable o no-op segun contrato.
3. En una segunda base descartable, cargar fixtures sintéticos con duplicados deliberados para cada preflight: la migracion debe abortar ANTES de DDL y sin borrar filas.
4. Verificar con `information_schema`:
   - indices unicos esperados para identidad de catalogo;
   - tabla/configuracion de metodos de pago;
   - collations y longitudes reales de `serial`, `createdby` y claves relacionadas;
   - engine InnoDB en tablas financieras, carrito, tienda y capability/password.
5. No marcar GO si una migracion solo pasa en SQLite.

## Gates de locks y concurrencia

Las pruebas SQLite 8B solo demuestran **orden de lecturas y nivel de transaccion**; no demuestran exclusion mutua.

Implementar/ejecutar pruebas MariaDB con dos conexiones independientes y una barrera determinista para:

1. `unlockCatalog`: cambio de contraseña entre lectura de tienda y lectura de password; nunca emitir capability para la contraseña obsoleta.
2. Checkout readiness: cambio de credenciales/metodo mientras checkout mantiene lock de tienda; el pedido debe usar un snapshot coherente o abortar.
3. Dos checkouts con la misma/diferente `Idempotency-Key`: una sola orden/consumo de stock para replay exacto; 409 sin mutacion para mismo key/contenido distinto.
4. Cancelacion vs confirmacion/webhook: nunca restock de orden cobrada; `payment_exception` no equivale a fondos, pero bloquea cancelacion publica.
5. Doble webhook y doble aceptacion delivery: una sola transicion ganadora, sin duplicar transacciones, wallet, envio ni stock.
6. Capturar deadlock/timeout y verificar retry o respuesta fail-closed; no aceptar hangs.

Cada prueba debe registrar conexiones distintas, timestamps/barrera, resultado de ambas operaciones y estado final de todas las tablas afectadas. Un test secuencial o una sola conexion no cuenta como concurrencia.

## Gates del artefacto frontend

Antes de despliegue conjunto, servir el artefacto que realmente reemplazara al bundle historico y ejecutar smoke E2E contra ese artefacto, no solo Vite dev:

- no solicitudes a `/public/products/{id}`, `/public/orders/{ref}` ni `verify-password`;
- QR `data:image/...` local, sin `api.qrserver.com` ni folio;
- checkout envia `payment_method` e `Idempotency-Key`;
- pay/cancel envian keys deterministas;
- 401/403 de capability relockea sin cerrar sesion owner.

El directorio `public/` original permanece inmutable durante recuperacion. Por ello el lote fuente 8B puede revisarse/commitearse, pero **NO es desplegable** hasta definir el proceso aprobado que ensamble y publique el nuevo artefacto sin perder el original.

## Salida y destruccion

1. Exportar solo logs/resultados sanitizados (sin payloads, documentos ni secretos).
2. Detener y eliminar contenedor/VM y volumen temporal.
3. Verificar que el puerto local dejo de escuchar.
4. Borrar credenciales temporales fuera del repo.
5. Actualizar `MATRIZ-BRECHAS.md` y el reporte con conteos reales, SHA y resultado GO/NO-GO. Nunca convertir “runbook escrito” en “prueba ejecutada”.

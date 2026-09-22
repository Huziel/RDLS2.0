# Despliegue a produccion (FASE 6)

Runbook de puesta en produccion de las Fases 2 a 5: codigo y migraciones de inventario, checkout, lealtad, tienda publica y MercadoPago real. Todo lo que ejecuta escritura sobre la base remota es aditivo (`php artisan migrate --force`) y esta protegido por `app/Support/DestructiveDatabaseCommandGuard.php`.

## 1. Estado y precedencia

- Rama de despliegue: `recuperacion` (HEAD de FASE 5: `4c1d882`).
- Base remota: host `195.35.61.25` (produccion). Nunca conectarse sin los overrides de proceso o preflight verificados.
- Migraciones pendientes de produccion: `2026_09_21_000001` a `2026_09_21_000005` (maskup de FASE 3/4/5). Las migraciones `000001`-`000004` se validaron sobre MySQL 8.0.17 desechable con restauracion del dump (84 tablas); `000005` se valido igualmente y su preflight fue corregido (consulta `restock_key` solo si la columna existe).
- El guard destructivo bloquea `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`. Con `APP_ENV=prod*` los bloquea en firme; con otro entorno exige `ALLOW_DESTRUCTIVE_DB_COMMANDS=true` y `DESTRUCTIVE_DB_CONFIRMATION` con el fingerprint exacto para una base desechable. En produccion **no se usan**.

## 2. Pre-requisitos

- Acceso al host de produccion (SSH remoto o panel de hosting con consola) desde donde este clonado el repositorio.
- Credenciales MySQL con permiso DDL para la aplicacion.
- Aplicacion MP del vendedor con access token y el secreto del webhook configurado.
- Dominio publico del frontend (para `FRONTEND_URL` y `MERCADOPAGO_NOTIFICATION_URL`).

## 3. Backup (siempre primero, no negociable)

```bash
# en el host de produccion o con acceso mysql remoto
mysqldump -h 195.35.61.25 -u APP_USER -p APP_DATABASE > backup_fase5_$(date +%F).sql
# verificar que el dump abre y tiene las 84 tablas
grep -c "CREATE TABLE" backup_fase5_$(date +%F).sql
```

Guardar el dump fuera del host (descarga local) antes de continuar.

## 4. Preflight de la base (solo lectura)

```sql
SHOW TABLES LIKE 'ordencompra';
SHOW TABLES LIKE 'mercadopago';
SELECT @@version;
SHOW CREATE TABLE ordencompra;   -- collation esperada utf8mb3_unicode_ci (o compatible)
SELECT restock_key, COUNT(*) FROM ordencompra
 WHERE restock_key IS NOT NULL
 GROUP BY restock_key HAVING COUNT(*) > 1;   -- debe devolver 0 filas
```

- Si `ordencompra` o `mercadopago` no existen, la migracion `000005` aborta con `RuntimeException` (guard interno).
- Las migraciones `000001`-`000005` presumen el esquema legado (84 tablas del bundle original, incluidas `salidastock`, `ordencompra`, `mercadopago`, `lealtad`); no se aplican sobre una base vacia (`php artisan migrate --pretend` sobre SQLite vacio falla en `000002` por diseño). Validar SIEMPRE sobre una restauracion del dump.
- Si la consulta de duplicados devuelve filas, reconciliar antes de ejecutar la migracion.

## 5. Despliegue de codigo

```bash
git fetch origin && git checkout recuperacion && git pull origin recuperacion
composer install --no-dev --optimize-autoloader
composer dump-autoload -o   # si hubo cambios de providers/aliases
php artisan migrate --pretend    # inspeccionar el SQL antes de aplicarlo
php artisan migrate --force      # aplica SOLO lo pendiente y nuevo (nunca fresh/reset/rollback)
php artisan migrate --force      # segunda ejecucion debe decir "Nothing to migrate"
php artisan config:cache && php artisan route:cache
php artisan storage:link         # si no existe
```

> No commitear `public/uploads/` ni `public/qrcodes/` (arte del sistema); el frontend compilado bajo `public/` debe venir del build LOCAL de FASE 5, no del repositorio.

## 6. Env vars de produccion

En el `.env` del host (una vez que el codigo usa la config FASE 5), agregar/verificar:

```bash
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=195.35.61.25
DB_PORT=3306
DB_DATABASE=<base de produccion>
DB_USERNAME=<usuario app>
DB_PASSWORD=<password app>
FRONTEND_URL=https://<dominio-frontend>
MERCADOPAGO_WEBHOOK_SECRET=<secreto del webhook MP>
MERCADOPAGO_API_BASE_URL=https://api.mercadopago.com
MERCADOPAGO_NOTIFICATION_URL=https://<dominio>/api/v1/payments/webhook
```

- `MERCADOPAGO_NOTIFICATION_URL` debe coincidir con la configuracion del panel MP del vendedor.
- Cada tienda que quiera cobrar en publico necesita una cuenta en `mercadopago` con `merchantId` (access token del vendedor); sin ella el endpoint publico `pay` responde 422 "Esta tienda debe configurar su cuenta de MercadoPago".

## 7. Frontend (build FASE 5)

```bash
# desde el repositorio local (o CI)
cd frontend && npm ci && npm run build
# copiar el dist compilado al public/ del host
# opcional (manual, verificable): /public/store/<serial> de cada tienda
```

## 8. Verificacion post-despliegue

```sql
SHOW CREATE TABLE ordencompra;   -- order_state varchar(20), cancelled_at, restock_key + UNI online_restock_unique
SHOW CREATE TABLE mercadopago;  -- payment_id bigint unsigned
SELECT COUNT(*) FROM migrations; -- incluye 000001-000005
```

- `php artisan route:list --path=api/v1/payments` → `webhook`, `account`, `preference`.
- `php artisan route:list --path=api/v1/stores` → rutas `checkout`, `{order}/pay`, `{order}/status`, `{order}/cancel`, `products`.
- Smoke webhook: `POST /api/v1/payments/webhook` con firma invalida → 403; con firma valida y monto/colector incorrectos → no contamina (validado por `PaymentController`).
- Smoke publico: comprar en la vitrina de una tienda, pagar en sandbox/testeo, y confirmar que el folio pasa a `paid`; cancelar una orden pendiente y verificar que el stock vuelve una vez.

## 9. Backout

1. Restaurar el backup del paso 3 (misma herramienta que lo genero).
2. Re-deploy del commit anterior (`git checkout ea780d9 && git push origin recuperacion` en el host) y `composer install --no-dev` + `php artisan config:cache`.
3. El backout NO usa `migrate:rollback` (el guard lo impide en produccion); se restaura el dump completo.

## 10. Riesgos residuales

- La integracion MP depende de configuracion externa (panel MP): secret, notification_url y cuentas por tienda.
- SQLite valida contratos, pero los locks concurrentes Solo se prueban sobre InnoDB real (ya validados en el MySQL desechable de FASE 4/5).
- El dump de restauracion es del 20-09-2026; escrituras posteriores al respaldo en produccion requieren reconciliacion si se usara para backout.

Referencias: `docs/public-store-reconstruction.md`, `docs/mercadopago-integration.md`, `docs/order-cancellation-stock.md`, `docs/pos-checkout-loyalty-reconstruction.md`, `app/Support/DestructiveDatabaseCommandGuard.php`.
# Reconstruccion POS, checkout y lealtad

Documento tecnico de FASE 4. Esta fase reconstruye las superficies confirmadas de Punto de Venta e historial y conecta POS y checkout online con las mismas reglas transaccionales de inventario y lealtad.

## Alcance

- POS responsive con ordenes activas, cliente, telefono, busqueda, categorias y barcode.
- Cantidades, cargo extra, guardado, eliminacion y cobro.
- Metodos `efectivo`, `tarjeta` y `transferencia`.
- Consulta y canje de lealtad dentro del cobro final.
- Ticket imprimible e historial con fechas, metodo, metricas y detalle.
- Consumo de inventario en pago POS y creacion de orden online.
- Checkout online idempotente con envio y disponibilidad recalculados por backend.
- Locks e idempotencia para inventario, lealtad, pagos y folios.
- Proteccion global contra comandos destructivos de Artisan.

No se creo un modulo de movimientos, almacenes, reservas o devoluciones porque siguen sin existir en el sistema historico.

## Decisiones transaccionales

### POS

```text
orden estado 0
  -> agregar snapshots de producto y precio
  -> guardar: recalcular subtotal + extra, estado 1
  -> cobrar, dentro de una transaccion
       bloquear orden
       bloquear saldos por id de producto ordenado
       validar tenant, actividad y disponibilidad
       canjear puntos con lock
       recalcular total
       descontar stock
       crear historial y detalles
       marcar orden estado 2
       acreditar puntos
```

Un segundo cobro devuelve la venta historica con `idempotent: true`. No descuenta stock, no crea otro historial y no vuelve a mover puntos.

### Checkout online

```text
carrito estado 0
  -> bloquear lineas activas de sesion + tienda
  -> resolver checkout_key
  -> bloquear y consumir saldos
  -> recalcular envio en backend
  -> crear orden y direccion
  -> marcar lineas estado 2
  -> acreditar puntos
```

El inventario se consume al crear la orden, no al confirmar el pago. Esta decision evita overselling con el flujo historico, donde checkout ya materializa el pedido. Todavia no existe cancelacion automatica con reposicion; cualquier cambio de esa politica requiere una fase de negocio explicita.

`Idempotency-Key` es opcional, no vacio y de hasta 100 caracteres. Backend almacena un hash SHA-256 de tienda, token de carrito y llave, por lo que la misma llave usada por dos carritos no colisiona. Si no se envia, la llave se deriva de tienda, token y lineas. Solo un reintento con llave explicita puede recuperar una orden despues de que el carrito queda vacio.

El checkout publico no permite canjear puntos porque un telefono por si solo no demuestra propiedad de la cuenta. Si el telefono pertenece a un cliente, la compra terminada si puede acreditar puntos. El canje queda restringido al POS autenticado.

Pickup, envio local y envio nacional se validan contra las funciones habilitadas de la tienda. Mientras no exista geocodificacion firmada por backend, el envio local cobra el mayor nivel configurado; no confia en coordenadas suministradas por el navegador para abaratar una direccion.

## Inventario compartido

`App\Actions\Inventory\ConsumeInventory` es la unica operacion reconstruida para confirmar una venta. Recibe propietario de tienda y cantidades agrupadas, ordena IDs, valida todos los productos y bloquea `stock` con `FOR UPDATE` antes de descontar.

- Producto inactivo o ajeno: 422.
- Saldo ausente o insuficiente: 422.
- Cualquier error revierte orden, stock, historial y lealtad.
- La edicion absoluta de stock en Productos tambien bloquea el saldo.

La prueba real sobre InnoDB ejecuto dos consumos simultaneos contra una unidad: uno confirmo, uno fue rechazado y el saldo final fue cero.

## Lealtad

`LoyaltyPoint::addPoints` y `redeemPoints` bloquean el saldo `(store_id, client_id)` y registran un ledger idempotente por `(store_id, type, reference)`.

- El cliente siempre se resuelve dentro de la tienda.
- El canje exige programa activo, minimo configurado y saldo suficiente.
- El descuento no puede superar el total.
- POS usa el folio como referencia.
- Checkout usa el folio como referencia idempotente de acumulacion.
- La acumulacion usa el total final despues del descuento.

La prueba real sobre InnoDB ejecuto dos canjes simultaneos de 80 sobre un saldo de 100: uno confirmo, uno fue rechazado, el saldo final fue 20 y se creo un solo asiento.

## Contrato HTTP POS

Todos los endpoints requieren Sanctum. El catalogo permite `products.read` o `pos.use`; modificar Productos sigue requiriendo sus permisos administrativos.

| Metodo | URL | Permiso | Funcion |
| --- | --- | --- | --- |
| `GET` | `/api/v1/pos/orders` | `pos.use` | Ordenes activas del tenant |
| `POST` | `/api/v1/pos/orders` | `pos.use` | Crear orden y cliente opcional |
| `POST` | `/api/v1/pos/orders/{id}/products` | `pos.use` | Agregar snapshot desde producto activo |
| `PUT` | `/api/v1/pos/orders/{id}/products/{detail}` | `pos.use` | Cambiar cantidad |
| `DELETE` | `/api/v1/pos/orders/{id}/products/{detail}` | `pos.use` | Retirar linea |
| `DELETE` | `/api/v1/pos/orders/{id}` | `pos.use` | Eliminar orden pendiente |
| `POST` | `/api/v1/pos/orders/{id}/save` | `pos.use` | Guardar subtotal y extra |
| `POST` | `/api/v1/pos/orders/{id}/pay` | `pos.use` | Cobro atomico con inventario y lealtad |
| `POST` | `/api/v1/pos/loyalty/check` | `pos.use` | Consultar puntos por telefono y tenant |
| `POST` | `/api/v1/pos/loyalty/redeem` | `pos.use` | Canje protegido e idempotente |
| `GET` | `/api/v1/pos/history` | `pos.history` | Historial, filtros y metricas agregadas |
| `GET` | `/api/v1/pos/ticket/{folio}` | `pos.history` | Ticket pagado del tenant |

Filtros de historial: `from=Y-m-d`, `to=Y-m-d`, `payment=efectivo|tarjeta|transferencia` y `per_page` entre 1 y 100. El mapeo historico se conserva: `1=Efectivo`, `2=Tarjeta`, `3=Transferencia`.

## Seguridad de ordenes y pagos

- El detalle publico de una orden exige el mismo `X-Cart-Token` que creo el pedido.
- Listado, detalle, confirmacion y cargos administrativos se restringen a la tienda autenticada.
- La preparacion MercadoPago ya no puede consultar ordenes de otra tienda.
- El webhook exige `X-Signature`, `X-Request-Id` y `MERCADOPAGO_WEBHOOK_SECRET`.
- El webhook vuelve a consultar el pago en MercadoPago y compara estado, referencia y monto antes de confirmar.
- Cada cuenta guarda el `merchantId` verificado por MercadoPago; el webhook usa el token de esa tienda y compara `collector_id` y propietario.
- La confirmacion bloquea el registro de pago y actualiza solo carritos del mismo folio y serial.

El endpoint historico llamado `preference` conserva su respuesta de metadatos; todavia no crea una preferencia remota en MercadoPago. La reconstruccion de la integracion de cobro online completa queda fuera de esta fase.

## Proteccion de bases

`App\Support\DestructiveDatabaseCommandGuard` intercepta `CommandStarting` y bloquea:

- `db:wipe`
- `migrate:fresh`
- `migrate:refresh`
- `migrate:reset`
- `migrate:rollback`

Produccion siempre se bloquea, incluso con variables de override. SQLite `:memory:` se permite durante PHPUnit. Una base local desechable exige simultaneamente:

```dotenv
ALLOW_DESTRUCTIVE_DB_COMMANDS=true
DESTRUCTIVE_DB_CONFIRMATION=mysql:mysql:127.0.0.1:3307/disposable_database
```

La huella debe coincidir exactamente con conexion, driver, host, puerto y base. `migrate` y `schema:dump` no se clasifican como destructivos. PHPUnit ademas aborta si la conexion no es SQLite `:memory:`.

## Migracion preparada

`2026_09_21_000004_harden_checkout_and_loyalty.php` agrega:

- `ordencompra.checkout_key` nullable;
- `ordencompra.loyalty_discount`;
- unique global de `order` y unique `(serial, checkout_key)`;
- unique de configuracion por tienda;
- unique de referencias de lealtad;
- unique de pago por folio y cuenta MercadoPago por usuario;
- identificador de comercio verificado para cada cuenta MercadoPago;
- preflight de duplicados antes de cada DDL;
- operaciones condicionales para reintentos parciales.

Las migraciones `000001` a `000004` se probaron sobre MySQL 8.0.17 desechable con una restauracion local de las 84 tablas del dump MariaDB. Se verificaron cero stocks negativos, cero stocks huerfanos, indices nuevos, segunda ejecucion sin cambios y locks concurrentes reales. La unica adaptacion del dump fue reemplazar en la copia temporal la collation exclusiva de MariaDB `utf8mb3_uca1400_ai_ci` por `utf8mb3_unicode_ci`; el dump original no se modifico.

Ninguna migracion fue ejecutada contra produccion.

## Despliegue pendiente

1. Crear un respaldo nuevo y verificar que pueda restaurarse.
2. Configurar credenciales y secreto webhook MercadoPago.
3. Volver a guardar las cuentas MercadoPago existentes para verificar y persistir su `merchantId`; hasta entonces no se crean metadatos de pago ni se confirman webhooks para esa tienda.
4. Revisar los preflight de duplicados inmediatamente antes del despliegue.
5. Ejecutar las migraciones en orden `000001`, `000002`, `000003`, `000004` mediante el proceso autorizado.
6. Verificar indices, conteos, stocks negativos/huerfanos y permisos.
7. Ejecutar una venta POS, un checkout y sus reintentos controlados.

## Riesgos residuales

- Las tablas centrales siguen siendo esquema legacy y no nacen de migraciones base del repositorio.
- Precio, cantidad y stock conservan tipos legacy de texto/double en produccion.
- No existe reposicion automatica al cancelar una orden online.
- Stock de addons sigue siendo informativo y no se descuenta.
- Ventas POS pagadas permanecen en tabla activa estado 2 y en historial por compatibilidad.
- Los folios historicos ya redondeados no pueden reconstruirse.
- La preferencia MercadoPago remota sigue pendiente.

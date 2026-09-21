# Reconstruccion de inventario, stock y categorias

Documento tecnico de FASE 3. Cada hallazgo se clasifica como:

- `CONFIRMADO`: existe evidencia directa en Laravel, la base remota o el bundle historico.
- `INFERIDO`: consecuencia consistente con la evidencia, sin contrato explicito suficiente.
- `NO CONFIRMADO`: concepto buscado sin evidencia para reconstruirlo.

La auditoria funcional de la base se realizo mediante `SELECT` e `INFORMATION_SCHEMA`. Durante una revision automatizada posterior se ejecuto accidentalmente `migrate:fresh` contra la conexion remota; el esquema fue restaurado inmediatamente desde el dump autorizado `u119629285_RutaDeLaSeda (1).sql` del 20-09-2026. La verificacion posterior recupero 84 tablas, 25 productos, 25 saldos, tres usuarios, tres tiendas, tres ordenes online, 15 ordenes POS y 14 historiales, sin stocks huerfanos. Esto confirma la restauracion al punto del dump, pero no demuestra si existieron escrituras posteriores al respaldo que deban reconciliarse. Ninguna migracion de FASE 2 o FASE 3 fue aplicada a produccion. El frontend compilado en `public/` no fue modificado.

## 1. Conclusion de la auditoria

- `CONFIRMADO`: el sistema original no tenia un modulo independiente de Inventario.
- `CONFIRMADO`: no existen rutas, chunks, tablas ni endpoints de movimientos, entradas, salidas, ajustes, transferencias o almacenes.
- `CONFIRMADO`: Productos es la unica superficie de mantenimiento manual de existencias.
- `CONFIRMADO`: `stock.stock` representa el saldo absoluto actual de un producto.
- `CONFIRMADO`: Categoria es texto libre en `data.category`; no existe entidad Category ni CRUD.
- `CONFIRMADO`: solo el pago POS descuenta stock automaticamente.
- `CONFIRMADO`: carrito, checkout, pago online, delivery, apartados y trueques no modifican stock.
- `CONFIRMADO`: el frontend historico mostraba stock en Productos, POS, dashboard y catalogo publico.

Por fidelidad no se creo una ruta `/inventory`, un ledger ficticio ni un CRUD de categorias. La FASE 3 recupera las superficies confirmadas y prepara el contrato que utilizara POS; su despliegue a produccion sigue pendiente.

## 2. Mapa tecnico

```text
Store.createdby
  -> Product.session
      -> ProductStock.idProd       saldo absoluto
      -> ProductBarcode.idProd     codigo opcional
      -> ProductAddon.idProd       extra con stock informativo
      -> PosOrderDetail.productoId snapshot de venta
      -> Cart.product              linea de compra online

POS pendiente (pventageneral, estado 0)
  -> POS guardado (estado 1)
      -> pago atomico
          -> ProductStock decrementado
          -> pventageneralhisto + detalles
          -> POS pagado (estado 2)
```

El tenant de Producto no usa `store_id`: se resuelve por la igualdad `Product.session = Store.createdby = User.name`.

## 3. Tablas auditadas

### `data`

Tabla principal de productos.

| Columna | Tipo remoto | Regla relevante |
| --- | --- | --- |
| `id` | `int` PK AI | Identificador global |
| `number` | `varchar(255)` | Precio almacenado como texto |
| `keyy` | `varchar(255)` | Nombre |
| `session` | `varchar(255)` nullable | Tenant logico |
| `category` | `varchar(50)` nullable | Categoria textual |
| `active` | `int` nullable, default 1 | Estado |

Auditoria remota: 25 productos, dos tenants, 21 activos y cuatro inactivos.

### `stock`

| Columna | Tipo remoto | Regla relevante |
| --- | --- | --- |
| `id` | `int` PK AI | Identificador |
| `idProd` | `int` FK | Producto, cascade update/delete |
| `stock` | `double` nullable | Saldo absoluto actual |
| `typesd` | `int` nullable | Sin uso funcional confirmado |

Evidencia remota:

- 25 filas para 25 productos.
- No habia productos sin stock ni filas huerfanas.
- No habia duplicados por `idProd`, pero el indice remoto no era unique.
- Cero valores null, cero negativos y cuatro saldos en cero.
- Rango observado de 0 a 12; `typesd` era null en todas las filas.
- No tiene fecha, delta, motivo, usuario, referencia ni almacen; no es historial.

### `cbarras`

| Columna | Tipo remoto | Regla relevante |
| --- | --- | --- |
| `id` | `int` PK AI | Identificador |
| `idProd` | `int` FK | Producto, no unique en remoto |
| `code` | `double` | Sin indice ni unique en remoto |

La tabla estaba vacia. El esquema permitia multiples filas por producto y codigos duplicados, ademas de perder ceros iniciales o precision numerica.

### `aditivos`

Contiene `idProd`, nombre, precio, categoria, descripcion, estado y stock entero. Estaba vacia. Su stock no se valida ni descuenta al comprar, por lo que actualmente es informativo.

### POS

- `pventageneral`: orden de trabajo; estados observados 1 y 2.
- `pventageneraldetalle`: snapshots de producto, cantidad y precios.
- `pventageneralhisto`: cabecera pagada.
- `pventageneraldetallehisto`: detalles pagados.

`noOrder` era `DOUBLE` en ambas cabeceras. Los folios de 17 digitos perdian precision: ninguna de las 14 ventas historicas coincidia exactamente con su folio actual. La migracion preparada cambia futuros folios a `VARCHAR(50)`; no puede reconstruir digitos ya redondeados.

### Ordenes online

- `cart` referencia Producto y guarda cantidad, precio, tienda, token, orden y estado.
- `ordencompra` es la cabecera online.
- La relacion por folio es logica, no FK.
- Tres ordenes y cuatro filas de carrito fueron observadas.
- No existe reserva ni decremento de stock en este flujo.

### Ausencias confirmadas

No existen tablas de:

- movimientos o ledger;
- almacenes o sucursales de inventario;
- transferencias;
- recepciones o compras;
- reservas;
- devoluciones o restock;
- conteos fisicos;
- entidad maestra de categorias.

## 4. Models y controllers

### Models

- `Product`: tabla `data`, tenant por `session`, relaciones `stock`, `barcode`, `images`, `addons`.
- `ProductStock`: tabla `stock`, `belongsTo Product`.
- `ProductBarcode`: tabla `cbarras`, `belongsTo Product`.
- `PosOrder` y `PosOrderDetail`: orden POS activa.
- `PosOrderHistory` y `PosOrderDetailHistory`: snapshot pagado.
- No existe `Inventory`, `Movement`, `Warehouse` ni `Category`.

### Controllers

- `ProductController`: listado, saldo inicial, reemplazo absoluto de stock y barcode.
- `CategoryController`: strings distinct por tenant.
- `PosController`: seleccion, guardado, pago, historial y ticket.
- `DashboardController`: calcula `low_stock` con `0 < stock < 5`.
- `CartController`/`Checkout`: no cambian stock.

No existen Services, Repositories, Policies, Events, Listeners, Jobs u Observers de inventario.

## 5. Contrato HTTP actual

Todos los endpoints privados usan `auth:sanctum`.

| Metodo | URL | Permiso | Inventario |
| --- | --- | --- | --- |
| `GET` | `/api/v1/products` | `products.read` | Lista saldo plano y barcode por tenant |
| `POST` | `/api/v1/products` | `products.create` | Crea saldo inicial absoluto |
| `GET` | `/api/v1/products/{id}` | `products.read` | Devuelve saldo como `{cantidad,type}` |
| `PUT/PATCH` | `/api/v1/products/{id}` | `products.update` | Reemplaza saldo absoluto dentro de transaccion |
| `GET` | `/api/v1/products/search-barcode?code=` | `products.read` | Busca producto activo del tenant y devuelve stock |
| `GET` | `/api/v1/categories` | permiso de lectura/creacion/edicion de Productos | Devuelve strings distinct del tenant |
| `GET` | `/api/v1/dashboard/stats` | `dashboard.view` | Resume ventas, actividad y stock bajo del tenant |
| `GET` | `/api/v1/pos/orders` | `pos.use` | Ordenes POS activas |
| `POST` | `/api/v1/pos/orders/{id}/products` | `pos.use` | Solo producto activo del tenant |
| `POST` | `/api/v1/pos/orders/{id}/save` | `pos.use` | Valida lineas y cargo extra; no descuenta |
| `POST` | `/api/v1/pos/orders/{id}/pay` | `pos.use` | Valida y descuenta stock atomicamente |
| `GET` | `/api/v1/pos/history` | `pos.history` | Historial de ventas, no movimientos de stock |
| `GET` | `/api/v1/pos/ticket/{folio}` | `pos.history` | Ticket pagado del tenant |

No existe endpoint de ajuste incremental. La edicion de Productos es el contrato historico de valor absoluto.

## 6. Flujo exacto de stock

### Aumentos

- `CONFIRMADO`: solo mediante alta/edicion manual de Producto, enviando un valor absoluto mayor.
- `NO CONFIRMADO`: no hay recepciones, devoluciones ni compras que aumenten automaticamente.

### Disminuciones

- `CONFIRMADO`: el pago POS descuenta la suma de cantidades por producto.
- `CONFIRMADO`: agregar o guardar una orden POS no descuenta.
- `CONFIRMADO`: carrito, checkout y pagos online no descuentan.
- `CONFIRMADO`: cancelar/eliminar ordenes no repone stock porque nunca lo reservaron.

### Proteccion reconstruida

El pago POS ahora ejecuta en una transaccion:

1. Bloquea la orden en estado 1 con `lockForUpdate`.
2. Agrupa cantidades por producto.
3. Verifica que todos los productos esten activos y pertenezcan a la tienda.
4. Bloquea los saldos en orden por `idProd`.
5. Rechaza saldo inexistente o insuficiente con 422.
6. Crea cabecera y detalles historicos.
7. Decrementa cada saldo.
8. Marca la orden como pagada.

Un segundo pago ya no puede leer simultaneamente la misma orden elegible. La edicion absoluta de Producto tambien bloquea tienda/producto y actualiza saldo, barcode e imagenes dentro de una transaccion.

## 7. Categorias

- `CONFIRMADO`: `data.category` es nullable y textual.
- `CONFIRMADO`: el formulario permite texto libre.
- `CONFIRMADO`: `/categories` usa `distinct`, orden alfabetico y tenant.
- `CONFIRMADO`: una categoria desaparece cuando ningun producto conserva exactamente ese texto.
- `CONFIRMADO`: no hay ID, estado, slug, orden, jerarquia ni borrado.
- `NO CONFIRMADO`: no existe evidencia para un CRUD independiente.

La reconstruccion conserva este comportamiento. Normalizar a una tabla Category seria una funcionalidad nueva y una migracion de negocio, no recuperacion fiel.

## 8. Codigo de barras

- Busqueda exacta y tenant-aware.
- Solo devuelve productos activos para que POS no agregue articulos deshabilitados.
- Incluye saldo actual en la respuesta.
- Duplicados entre tiendas se permiten y se resuelven por tenant.
- Duplicados dentro de la misma tienda se rechazan con 422.
- Creacion/edicion se serializan por tienda para evitar dos asignaciones concurrentes del mismo codigo.

La migracion preparada cambia `code` de `DOUBLE` a `VARCHAR(255)`, crea indice de busqueda y permite como maximo una fila barcode por producto. No se ejecuto en produccion.

## 9. Evidencia del frontend original

No se encontro ruta/chunk de Inventario. Las superficies confirmadas fueron:

- `/dashboard/products`: tabla con Imagen, Nombre, Precio, Categoria, Stock, Activo y Acciones.
- Formulario Producto: stock absoluto, categoria libre y barcode.
- `/dashboard/pos`: stock por tarjeta y color rojo bajo 5; no deshabilitaba saldo cero.
- Dashboard: tarjeta condicional `Stock bajo`.
- Catalogo publico: `N en stock` o `Agotado`.
- `/dashboard/pos/history`: historial de ventas, no movimientos.

La FASE 3 restituye la columna/card de stock en Productos y la tarjeta condicional de stock bajo en Dashboard. POS todavia permanece como modulo pendiente para FASE 4.

## 10. Permisos y tenant

- No se inventaron permisos `inventory.*`.
- Stock manual reutiliza `products.update` porque forma parte del payload Producto.
- Consulta de stock/barcode reutiliza `products.read`.
- POS usa permisos existentes `pos.use` y `pos.history`.
- Una migracion de datos preparada garantiza ambos permisos al rol `store-owner`.
- Productos y categorias se filtran por `Store.createdby`.
- POS ya no acepta IDs de producto de otra tienda.
- Pago POS vuelve a comprobar tenant y actividad dentro de la transaccion.

## 11. Migraciones preparadas y no ejecutadas

`2026_09_21_000002_harden_inventory_constraints.php`:

- preflight de duplicados antes de ejecutar DDL no transaccional;
- unique `stock.idProd`;
- cambia `cbarras.code` a string;
- unique `cbarras.idProd`;
- indice para `cbarras.code`;
- cambia ambos `noOrder` POS de double a string;
- unique `(creator,noOrder)` en ordenes POS activas e historicas;
- operaciones condicionales para permitir reintento tras un despliegue parcial.

`2026_09_21_000003_backfill_store_owner_pos_permissions.php`:

- crea si faltan `pos.use` y `pos.history`;
- los asigna a `store-owner`.

Ninguna migracion de FASE 2 o FASE 3 fue ejecutada contra la base remota despues de restaurarla.

## 12. Mejoras realizadas

- Stock visible nuevamente en tabla desktop y cards moviles de Productos.
- Alerta historica `Stock bajo` restaurada en Dashboard.
- Edicion Producto atomica y tenant-aware.
- Barcode unico dentro de cada tienda a nivel de aplicacion.
- Busqueda barcode restringida a productos activos.
- POS rechaza productos ajenos o inactivos.
- POS exige permisos sembrados.
- Pago POS idempotente respecto al estado y protegido con locks.
- Saldo insuficiente/missing devuelve 422 sin escrituras parciales.
- Orden vacia y cargo extra invalido se rechazan al guardar.
- PHPUnit usa SQLite `:memory:` forzado; nunca la conexion remota.

## 13. Diferencias respecto al original

- El original mostraba stock pero permitia intentar vender saldo cero; backend ahora impide confirmar stock insuficiente.
- El original no aplicaba permisos POS en rutas; ahora usa los permisos ya sembrados.
- El original permitia barcode ambiguo dentro de una tienda; ahora devuelve 422 al duplicarlo.
- No se agregaron filtros de stock, ajustes incrementales, exportaciones ni graficos porque no existe evidencia historica.

## 14. Riesgos y pendientes

- Online checkout/pago no consume inventario; definir el momento de reserva/commit requiere una decision de negocio.
- No existe movimiento historico ni auditoria de ajustes manuales.
- `Product.session` usa email/nombre en vez de un `store_id` estable.
- La base remota guarda precio como texto y cantidad/stock como double.
- Los folios POS historicos ya redondeados no pueden recuperarse automaticamente.
- Ventas pagadas permanecen tanto en tablas activas estado 2 como en historial; consumidores pueden duplicarlas.
- Addon stock es informativo y nunca se descuenta.
- Canjes simultaneos de lealtad todavia pueden competir porque ese saldo no usa locks; debe resolverse junto con POS en FASE 4.
- El dashboard excluye stock cero/negativo de `low_stock` por comportamiento historico.
- La migracion unique requiere verificar nuevamente duplicados inmediatamente antes de desplegarla.
- SQLite valida contratos y rollback, pero no reproduce locks concurrentes de InnoDB.

## 15. Contrato para FASE 4 POS

```text
Producto activo del tenant
  -> precio: Product.number
  -> barcode: ProductBarcode.code
  -> saldo: ProductStock.stock

POS
  -> carga GET /products?active=1&per_page=500
  -> busca GET /products/search-barcode?code=...
  -> agrega snapshot a PosOrderDetail
  -> guarda orden sin tocar stock
  -> paga mediante POST /pos/orders/{id}/pay
  -> backend valida y descuenta stock atomicamente
  -> consulta ventas en /pos/history
```

FASE 4 debe reconstruir POS e historial usando este contrato, sin escribir directamente en `stock` desde Vue.

# Reconstrucción de Productos

Documento técnico de FASE 2. La clasificación utilizada es:

- `CONFIRMADO`: existe evidencia directa en Laravel, la base de datos, la API o el bundle compilado.
- `INFERIDO`: conclusión consistente con varias evidencias, pero sin contrato explícito o datos reales suficientes.
- `NO CONFIRMADO`: concepto buscado sin evidencia suficiente para implementarlo.

Durante la auditoría original de FASE 2 no se ejecutaron operaciones de escritura contra la base remota. El incidente operativo posterior de FASE 3 y su restauración están documentados en `inventory-reconstruction.md`. El frontend compilado de `public/` no fue modificado.

## 1. Evidencia encontrada

### Laravel

- `CONFIRMADO`: rutas en `routes/api.php` bajo `/api/v1`.
- `CONFIRMADO`: CRUD en `app/Http/Controllers/Api/V1/ProductController.php`.
- `CONFIRMADO`: extras en `app/Http/Controllers/Api/V1/ProductAddonController.php`.
- `CONFIRMADO`: categorías en `app/Http/Controllers/Api/V1/CategoryController.php`.
- `CONFIRMADO`: uploads en `app/Http/Controllers/Api/V1/UploadController.php`.
- `CONFIRMADO`: recursos `ProductResource`, `ProductDetailResource` y `ProductAddonResource`.
- `CONFIRMADO`: modelos `Product`, `ProductStock`, `ProductBarcode`, `ProductImage` y `ProductAddon`.
- `CONFIRMADO`: no existen Form Requests, Policies, Gates, Events, Jobs, Observers ni Repositories de Productos.
- `CONFIRMADO`: no existen migraciones para las tablas legacy principales de Productos.

### Frontend compilado

- `CONFIRMADO`: `public/assets/ProductListView-DI-aMaKs.js` y `ProductListView-CEnhlABv.css` contienen el listado.
- `CONFIRMADO`: `public/assets/ProductForm-Dg2dkalY.js` y `ProductForm-ClBShLGi.css` contienen alta/edición.
- `CONFIRMADO`: `public/assets/products-D3fqIN6u.js` contiene el cliente API original.
- `CONFIRMADO`: `public/assets/ImageUpload-DQ95XCYo.js` contiene el upload individual.
- `CONFIRMADO`: rutas originales `/dashboard/products`, `/dashboard/products/create` y `/dashboard/products/:id/edit`.
- `CONFIRMADO`: no existen source maps ni manifest útil para recuperar los SFC originales.

### Base de datos remota

- `CONFIRMADO`: MariaDB 11.8.9.
- `CONFIRMADO`: auditoría mediante `SELECT` e `INFORMATION_SCHEMA`; no se ejecutaron comandos mutantes.
- `CONFIRMADO`: 25 productos al momento de la auditoría, 21 activos y 4 inactivos.
- `CONFIRMADO`: nueve categorías distintas, sin documentar valores reales.
- `CONFIRMADO`: todos los productos observados tenían una fila de stock; no había stock negativo.
- `CONFIRMADO`: barcode, extras, cupones y apartados estaban vacíos, por lo que sus dominios reales no pudieron observarse.

## 2. Mapa técnico

```text
Product (data)
├── stock (stock.idProd -> data.id)
├── barcode (cbarras.idProd -> data.id)
├── images (img.product -> data.id, relación lógica)
├── addons (aditivos.idProd -> data.id)
├── carts (cart.product -> data.id)
├── coupon products (productcupon.idData -> data.id)
├── POS details (productoId -> data.id, relación lógica)
├── QR product target (qr_codes.target_id, relación lógica)
└── layaways products (JSON, sin FK)
```

```text
Producto
→ ProductController / ProductAddonController / CategoryController
→ API /api/v1/products
→ listado, alta y edición Vue
→ POS, tienda pública, marketplace, cupones, QR y apartados
```

## 3. Tablas

### `data`

`CONFIRMADO`: tabla principal del producto.

| Columna | Tipo real | Nullable | Uso |
| --- | --- | --- | --- |
| `id` | `int(255)` AI PK | No | Identificador |
| `number` | `varchar(255)` | No | Precio |
| `keyy` | `varchar(255)` | No | Nombre |
| `link` | `varchar(255)` | Sí | Imagen principal |
| `session` | `varchar(255)` | Sí | Propietario lógico, enlaza con `liks.createdby` |
| `dscr` | `text` | Sí | Descripción |
| `var` | `text` | Sí | Variable libre legacy |
| `category` | `varchar(50)` | Sí | Categoría textual |
| `active` | `int(10)` | Sí, default 1 | Estado activo |

### `stock`

| Columna | Tipo real | Nullable | Uso |
| --- | --- | --- | --- |
| `id` | `int(10)` AI PK | No | Identificador |
| `idProd` | `int(10)` FK | No | Producto |
| `stock` | `double` | Sí | Existencias |
| `typesd` | `int(10)` | Sí | Tipo legacy, sin uso confirmado |

- `CONFIRMADO`: FK `stock.idProd → data.id`, cascade update/delete.
- `CONFIRMADO`: la base permite duplicados aunque Eloquent usa `hasOne`.

### `cbarras`

| Columna | Tipo real | Nullable | Uso |
| --- | --- | --- | --- |
| `id` | `int(10)` AI PK | No | Identificador |
| `idProd` | `int(10)` FK | No | Producto |
| `code` | `double` | No | Código de barras |

- `CONFIRMADO`: FK cascade hacia `data`.
- `CONFIRMADO`: no existe índice unique; Laravel trata el código como string aunque la columna es numérica.

### `img`

| Columna | Tipo real | Nullable | Uso |
| --- | --- | --- | --- |
| `id` | `int(10)` AI PK | No | Identificador |
| `picture` | `varchar(255)` | Sí | URL de imagen |
| `dom` | `varchar(255)` | Sí | Propietario legacy |
| `product` | `varchar(255)` | Sí | ID lógico de producto |

- `CONFIRMADO`: no hay FK ni índice hacia `data`; Laravel elimina imágenes explícitamente al borrar el producto.

### `aditivos`

| Columna | Tipo real | Nullable | Uso |
| --- | --- | --- | --- |
| `id` | `int(10)` AI PK | No | Identificador |
| `idProd` | `int(10)` FK | No | Producto |
| `nombre` | `varchar(50)` | No | Nombre |
| `precio` | `double` | No | Precio adicional |
| `categoria` | `varchar(50)` | No | Categoría |
| `descripcion` | `text` | No | Descripción |
| `activo` | `int(10)` | No | Estado |
| `stock` | `int(20)` | No | Existencias del extra |

- `CONFIRMADO`: FK cascade hacia `data`.

### Tablas consumidoras

- `CONFIRMADO`: `cart` tiene FK cascade `product → data.id`.
- `CONFIRMADO`: `productcupon` tiene FK cascade `idData → data.id`.
- `CONFIRMADO`: detalles POS guardan `productoId`, nombre y precios copiados; las relaciones con Producto son lógicas.
- `CONFIRMADO`: `qr_codes.target_id` representa un producto cuando `type=product`, sin FK.
- `CONFIRMADO`: `layaways.products` es JSON, sin FK ni validación interna de IDs.

## 4. Models y relaciones

### `App\Models\Product`

- Tabla: `data`.
- Sin timestamps.
- Fillable: `number`, `keyy`, `link`, `session`, `dscr`, `var`, `category`, `active`.
- Relaciones confirmadas: `store`, `images`, `stock`, `barcode`, `addons`.
- Scopes confirmados: por tienda y activos.

### Models auxiliares

- `ProductStock` → tabla `stock`, `belongsTo(Product::class, idProd)`.
- `ProductBarcode` → tabla `cbarras`, `belongsTo(Product::class, idProd)`.
- `ProductImage` → tabla `img`, relación lógica por `product`.
- `ProductAddon` → tabla `aditivos`, `belongsTo(Product::class, idProd)`.

## 5. Controllers y routes

### Endpoints privados

Todos usan `auth:sanctum` y la tienda se resuelve mediante el nombre del usuario autenticado.

| Método | URL | Acción | Respuesta |
| --- | --- | --- | --- |
| GET | `/api/v1/products` | Listado paginado | Resource collection `{data,links,meta}` |
| POST | `/api/v1/products` | Crear | `201 {data,message}` |
| GET | `/api/v1/products/{id}` | Editar/cargar detalle | `200 {data}` |
| PUT/PATCH | `/api/v1/products/{id}` | Actualizar | `200 {data,message}` |
| DELETE | `/api/v1/products/{id}` | Eliminación física | `200 {message}` |
| GET | `/api/v1/products/search-barcode` | Buscar por `code` | `200 {data}` o 404 |
| GET | `/api/v1/categories` | Categorías distintas | `200 {data:string[]}` |
| GET | `/api/v1/products/{id}/addons` | Listar extras | Resource collection |
| POST | `/api/v1/products/{id}/addons` | Crear extra | `201 {data,message}` |
| PUT | `/api/v1/products/{id}/addons/{addon}` | Actualizar extra | `200 {data,message}` |
| DELETE | `/api/v1/products/{id}/addons/{addon}` | Eliminar extra | `200 {message}` |
| POST | `/api/v1/upload/image` | Upload individual | `200 {data:{url},message}` |

### Endpoint listado: query params

- `CONFIRMADO`: `page`, `per_page` con default 20.
- `CONFIRMADO`: `search`, búsqueda `LIKE` únicamente sobre nombre.
- `CONFIRMADO`: `category`, coincidencia exacta; `all` omite filtro.
- `CONFIRMADO`: `active`, valor `1` o `0`.
- `CONFIRMADO`: orden fijo por ID descendente.
- `NO CONFIRMADO`: no existe ordenamiento configurable.

### Endpoints públicos relacionados

- `GET /api/v1/public/stores/{serial}/products`.
- `GET /api/v1/public/products/{id}`.
- `GET /api/v1/public/products/{id}/addons`.
- Endpoints marketplace bajo `/api/v1/marketplace/*`.

## 6. Campos y recursos

### Producto editable

| Campo API | Requerido | Regla Laravel | Persistencia |
| --- | --- | --- | --- |
| `nombre` | Sí al crear | string, max 255 | `data.keyy` |
| `precio` | Sí al crear | numeric, min 0 | `data.number` |
| `imagen` | No | nullable string | `data.link` |
| `descripcion` | No | nullable string | `data.dscr` |
| `variable` | No | nullable string | `data.var` |
| `categoria` | No | nullable string | `data.category` |
| `activo` | No | boolean | `data.active` |
| `stock` | No | nullable integer, min 0 | `stock.stock` |
| `codigo_barras` | No | nullable string al crear | `cbarras.code` |
| `imagenes` | No | nullable array de strings | `img.picture` |

### Extra editable

- `nombre`: required string max 255.
- `precio`: required numeric min 0.
- `categoria`: nullable string.
- `descripcion`: nullable string.
- `activo`: boolean.
- `stock`: nullable/sometimes integer min 0.

### Diferencias de recursos

- `CONFIRMADO`: listado devuelve `stock` escalar.
- `CONFIRMADO`: detalle devuelve `stock: {cantidad,type}`.
- `CONFIRMADO`: precios pueden llegar como strings.
- `CONFIRMADO`: el frontend reconstruido normaliza estas formas sin cambiar el payload Laravel.
- `CONFIRMADO`: `store_session` fue retirado de `ProductResource` para no exponer públicamente el identificador del propietario.
- `RECONSTRUIDO FASE 3`: la tabla desktop y las cards móviles muestran nuevamente el stock confirmado en el bundle original.

## 7. Upload de imágenes

- Endpoint: `POST /api/v1/upload/image` con `multipart/form-data`, campo `file`.
- MIME confirmados: JPEG, PNG, JPG, GIF y WebP.
- Tamaño máximo confirmado: 10 MiB.
- Respuesta confirmada: URL pública en `data.url`.
- `CONFIRMADO`: no existe endpoint de borrado del archivo físico.
- `CONFIRMADO`: reemplazar/quitar una URL no elimina el archivo anterior.
- `INFERIDO`: puede haber archivos huérfanos si el upload funciona y luego falla el guardado.

## 8. Permisos

- `CONFIRMADO`: permisos sembrados `products.read`, `products.create`, `products.update`, `products.delete`.
- `CONFIRMADO`: el rol `store-owner` recibe esos permisos.
- `CONFIRMADO`: el estado encontrado al auditar solo aplicaba `auth:sanctum`; no aplicaba permisos.
- `RECONSTRUIDO`: Laravel aplica `role_or_permission` a lectura, creación, actualización y eliminación, permitiendo también `super-admin`.
- `RECONSTRUIDO`: las rutas Vue exigen el permiso correspondiente y el listado oculta acciones no concedidas.
- `RECONSTRUIDO`: una migración asigna `store-owner` a propietarios legacy que todavía no tenían ningún rol, antes de exigir los permisos.
- `CONFIRMADO`: las rutas Vue también heredan `requiresAuth` y `userType: "1"` del dashboard.
- `DIFERENCIA`: el bundle original no ocultaba acciones individuales usando permisos; la reconstrucción conecta los permisos que ya estaban sembrados.

## 9. Eliminación y estado

- `CONFIRMADO`: `DELETE /products/{id}` ejecuta eliminación física; `Product` no usa SoftDeletes.
- `CONFIRMADO`: `activo` permite desactivar sin borrar y controla el listado público.
- `CONFIRMADO`: stock, barcode, extras y cart tienen cascade en la base real.
- `CONFIRMADO`: imágenes no tienen FK y se eliminan explícitamente.
- `CONFIRMADO`: los archivos físicos de imágenes no se eliminan.

## 10. Comportamiento original identificado

### Listado

- Búsqueda inmediata sin debounce.
- Categoría y estado como filtros.
- 20 registros por página.
- Thumbnail o texto “Sin imagen”.
- Precio, categoría, stock, estado y acciones Editar/Eliminar.
- Confirmación `¿Eliminar "nombre"?`.
- No existía detalle privado independiente.
- El original no presentaba correctamente errores de listado.
- El original no adaptaba su tabla específicamente a móvil.

### Alta y edición

- Un formulario compartido para crear/editar.
- Dos columnas en escritorio.
- Todos los campos confirmados de Producto.
- Imagen principal por URL/upload e imágenes adicionales por URL.
- Extras disponibles después de crear el producto.
- Redirección al listado después de guardar.
- Límite `max_products` comprobado en Vue.

## 11. Comportamiento reconstruido

- `ProductListView.vue`: listado, búsqueda inmediata, filtros, paginación, loaders, vacío, errores y eliminación.
- `ProductCreateView.vue`: alta, límite del plan, categorías, validación y mensajes.
- `ProductEditView.vue`: carga, edición, 404 y extras.
- `ProductForm.vue`: formulario compartido y payload exacto.
- `ProductTable.vue`: tabla desktop y cards móvil.
- `ProductImageField.vue`: URL, upload, validación y preview.
- `ProductAddonsEditor.vue`: CRUD visual de todos los campos confirmados.
- `useProductList.js`: estado asíncrono y protección contra respuestas fuera de orden.
- `products.js`: cliente API aislado.
- `products.js` en utils: adapters, validación, payloads y formato.
- El guardado se bloquea mientras una imagen está subiendo.
- El backend aplica también `max_products` al crear.
- El conteo y la creación se serializan por tienda dentro de una transacción para no exceder el plan con solicitudes simultáneas.
- La edición puede limpiar imagen, descripción, variable y categoría mediante `null`.
- La edición elimina la fila barcode cuando `codigo_barras` se limpia.
- La búsqueda por barcode se resuelve dentro de la tienda autenticada.
- La edición de producto, stock, barcode e imágenes se ejecuta dentro de una transacción con locks por tienda/producto.
- Barcode puede repetirse entre tiendas, pero no entre dos productos de la misma tienda.
- La búsqueda por barcode solo devuelve productos activos e incluye su saldo actual.
- Los errores internos se registran en Laravel y ya no se envían al cliente.

## 12. Dependencias

### Productos → POS

- `CONFIRMADO`: POS consulta productos, categorías y barcode.
- `CONFIRMADO`: copia nombre y precios en `pventageneraldetalle`.
- `CONFIRMADO`: descuenta stock al pagar.
- `RECONSTRUIDO FASE 3`: agregar productos valida tenant y actividad; pagar vuelve a validarlos y bloquea saldos para impedir stock negativo.
- `CONFIRMADO`: guardar una orden no reserva stock; la disponibilidad definitiva se comprueba al pagar.

### Productos → tienda pública

- `CONFIRMADO`: catálogo por serial consume productos activos, stock e imágenes.
- `CONFIRMADO`: detalle público consume imágenes y extras.
- `CONFIRMADO`: carrito referencia `data.id` y copia precio.
- `RECONSTRUIDO`: detalle y extras públicos rechazan productos inactivos.
- `RECONSTRUIDO`: carrito valida que producto y extras activos pertenezcan a la tienda solicitada.
- `RIESGO`: detalle público no valida serial ni contraseña de catálogo y el carrito no comprueba stock.

### Productos → cupones

- `CONFIRMADO`: cupones tipo producto usan `productcupon.idData`.
- `CONFIRMADO`: UI original carga hasta 500 productos para seleccionar.
- `RIESGO`: creación de cupón no valida pertenencia de los IDs enviados.

### Productos → QR

- `CONFIRMADO`: QR tipo `product` almacena el ID en `target_id` y genera una URL pública.
- `CONFIRMADO`: UI original carga hasta 500 productos.
- `RIESGO`: generación QR no valida existencia, actividad ni pertenencia del producto.

### Productos → apartados

- `CONFIRMADO`: `layaways.products` guarda un arreglo JSON.
- `CONFIRMADO`: UI original carga productos para construir el apartado.
- `RIESGO`: backend no valida estructura, IDs, precios ni tienda y no reserva/descuenta stock.

## 13. Conceptos no confirmados

- `NO CONFIRMADO`: subcategorías.
- `NO CONFIRMADO`: marcas.
- `NO CONFIRMADO`: unidades de medida funcionales.
- `NO CONFIRMADO`: presentaciones estructuradas.
- `NO CONFIRMADO`: variantes estructuradas; `var` es texto libre legacy.
- `NO CONFIRMADO`: impuestos.
- `NO CONFIRMADO`: almacenes o sucursales.
- `NO CONFIRMADO`: SKU.
- `NO CONFIRMADO`: movimientos o historial de inventario.
- `NO CONFIRMADO`: promociones separadas de cupones.

Ninguno de estos conceptos fue implementado.

## 14. Diferencias respecto al frontend original

- La tabla cambia a cards bajo 760 px; el original desbordaba en móvil.
- Los errores de API, timeout, 403 y 404 son visibles; el original podía parecer vacío o quedar cargando.
- Se evita que respuestas antiguas de búsqueda reemplacen resultados nuevos.
- El límite del plan bloquea de forma segura si no puede verificarse y se valida también en backend.
- El upload bloquea submit mientras está pendiente.
- Los errores 422 usan directamente la forma normalizada por Axios.
- Los extras permiten precio cero, válido en Laravel.
- No se creó una vista privada de detalle porque no existe evidencia de una ruta original.

## 15. Pendientes

- Pruebas backend de integración requieren una base aislada; no deben ejecutarse contra producción.
- Migraciones reproducibles para las tablas legacy.
- Extender el mismo esquema de permisos a cupones, QR y apartados cuando se reconstruyan; POS ya aplica `pos.use` y `pos.history`.
- Eliminación física/administración de archivos subidos.
- Correcciones multi-tenant y de stock en tienda pública, cupones, QR y apartados; POS ya protege tenant/saldo y el carrito aún debe comprobar/reservar stock.
- Índices/unique para stock, barcode, imágenes y consultas frecuentes.

## 16. Riesgos conocidos

- El esquema real guarda precio y varios totales como texto.
- Barcode es `double` en la base aunque la API usa string; puede perder ceros iniciales o precisión.
- La base permite más de una fila stock/barcode por producto aunque Eloquent usa `hasOne`.
- Upload y guardado no son una sola transacción.
- Los consumidores downstream todavía no comprueban todos sus permisos individuales.
- El servicio worker compilado referencia assets históricos inexistentes.

## 17. Decisiones técnicas

- Se conservaron Vue 3, Composition API, Router, Pinia para auth y Axios.
- No se añadió un store Pinia de Productos: el estado es local a la pantalla y se encapsula en `useProductList`.
- Se separaron API, adapters, composable, formulario, filtros, resultados, paginación, upload y extras.
- No se introdujeron campos o endpoints no confirmados.
- Se mantuvo búsqueda inmediata para fidelidad con el original.
- Los writes E2E se interceptan con Playwright; el modo `test` dirige cualquier API no mockeada al puerto local descartado `127.0.0.1:9`, nunca a la base remota.
- `frontend/dist` sigue aislado y no escribe en `public/`.

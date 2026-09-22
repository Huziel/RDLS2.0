# Tienda publica en Vue (reconstruccion)

Documento tecnico de FASE 5. Reconstruye la vitrina publica de cada tienda (catalogo, detalle, carrito, checkout, confirmacion) recuperada desde los frentes historicos, conectada a los endpoints publicos nuevos y a la integracion MercadoPago real.

## Alcance

- Vitrina `/store/:serial` con catalogo paginado, categorias y busqueda.
- Detalle de producto con addons, cantidad y total calculado.
- Carrito persistente por `X-Cart-Token`.
- Checkout con envio (recoge / local / nacional) y resumen recalculado en backend.
- Pantalla de gracias con detalle, pasos, transferencia, MercadoPago y cancelacion.
- No se crearon modulos nuevos de almacenes ni cuentas: el carrito publico sigue siendo el flujo historico basado en sesion.

## Componentes

| Archivo | Responsabilidad |
| --- | --- |
| `src/views/public/store/StoreHomeView.vue` | Catalogo, categorias, gate de contrasena, agregar al carrito |
| `src/views/public/store/ProductDetailView.vue` | Detalle, addons, cantidad, CTA con total |
| `src/views/public/store/CartView.vue` | Lineas, cantidades, cupon, resumen, ir a pagar |
| `src/views/public/store/CheckoutView.vue` | Formulario, tipo de envio, idempotencia, crear pedido |
| `src/views/public/store/ThankYouView.vue` | Pedido, transferencia, MercadoPago, verificar, cancelar |
| `src/api/public-store.js` | Cliente axios del flujo publico |
| `src/utils/public-store.js` | Token de carrito, formato de dinero, subtores |
| `src/styles/public-store.css` | Estilos base de la vitrina (registrado en `main.js`) |

Rutas registradas en `src/router/index.js` bajo el prefijo `/store/:serial`.

## Contrato del carrito

El token de carrito se genera con `crypto.randomUUID()` al primer acceso (`ensureCartToken`) y se persiste en `localStorage.cart_token`. Todos los endpoints del flujo publico lo reenvian en `X-Cart-Token`:

- `GET POST /api/v1/stores/{serial}/cart`
- `PUT DELETE /api/v1/stores/{serial}/cart/{cartId}`
- `DELETE /api/v1/stores/{serial}/cart` y `POST .../cart/coupon`
- `POST /api/v1/stores/{serial}/checkout`
- `POST /api/v1/stores/{serial}/orders/{order}/pay`
- `GET /api/v1/stores/{serial}/orders/{order}/status`
- `POST /api/v1/stores/{serial}/orders/{order}/cancel`
- `GET /api/v1/public/orders/{order}`

El detalle publico de una orden exige el mismo token que la creo; sin el, 404.

## Formas de respuesta consumidas

El interceptor de `src/api/client.js` devuelve el `data` HTTP completo; las vistas acceden a `body.data`:

- `theme`: `data{ extra, has_password, colors, features, shipping_costs }`; la funcion de envio nacional se lee de `features.national_shipping`.
- `cart`: `data{ items, cart_token, total, count }` con items `{ id, product_id, product_name, price, quantity, addons, discount, product_image }`.
- `checkout`: `data.order_id` (el folio historico).
- `publicOrderDetail`: `data{ order, cliente, telefono, fecha, total, envio, items[] }` con items `{ name, qty, price, addons[] }`.
- `pay`: `data{ order_id, total, public_key, init_point, sandbox_init_point }`.
- `status`: `data.status` en `pending`, `paid` o `cancelled`.
- Cada producto: `{ id, nombre, precio, imagen, descripcion, categoria, activo, stock, variable }`; el detalle publico anade `aditivos[]` con `{ id, nombre, precio }`. Nunca expone `store_session`.

## Pruebas

- `tests/unit/public-store.test.js`: 8 pruebas de vitest sobre utils (token, dinero, subtores, conteo) y helpers.
- `tests/e2e/public-store.spec.js`: Playwright con `page.route` simulando los endpoints; ejecuta en desktop y mobile sin depender del backend, el QR externo ni Google Fonts.

```text
npm test                          # vitest de unidades
npm run build                     # build de produccion
npm run test:e2e                  # Playwright (desktop + mobile)
```
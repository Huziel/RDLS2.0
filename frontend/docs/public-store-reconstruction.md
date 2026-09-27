# Tienda publica en Vue (reconstruccion)

Documento tecnico de FASE 5. Reconstruye la vitrina publica de cada tienda (catalogo, detalle, carrito, checkout, confirmacion) recuperada desde los frentes historicos, conectada a los endpoints publicos nuevos y a la integracion MercadoPago real.

## Alcance

- Vitrina `/store/:serial` con catalogo paginado, categorias y busqueda.
- Detalle de producto con addons, cantidad y total calculado.
- Carrito persistente por `X-Cart-Token`.
- Checkout con envio (recoge / local / nacional), forma de pago explicita y resumen recalculado en backend.
- Pantalla de gracias con proyeccion minima, estados financieros, transferencia, MercadoPago, cancelacion y QR local sin folio.
- No se crearon modulos nuevos de almacenes ni cuentas: el carrito publico sigue siendo el flujo historico basado en sesion.

## Componentes

| Archivo | Responsabilidad |
| --- | --- |
| `src/views/public/store/StoreHomeView.vue` | Catalogo, categorias, gate de contrasena, agregar al carrito |
| `src/views/public/store/ProductDetailView.vue` | Detalle, addons, cantidad, CTA con total |
| `src/views/public/store/CartView.vue` | Lineas, cantidades, cupon, resumen, ir a pagar |
| `src/views/public/store/CheckoutView.vue` | Formulario, tipo de envio, metodo de pago, readiness fail-closed, idempotencia, crear pedido |
| `src/views/public/store/ThankYouView.vue` | Pedido, estados financieros, transferencia, MercadoPago, verificar, cancelar, QR local |
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
- `GET /api/v1/stores/{serial}/orders/{order}`

El detalle publico de una orden exige el serial y el mismo token que la creo; sin cualquiera de los dos, 404. Las rutas de checkout y orden usan `Idempotency-Key`; checkout deriva una clave estable del serial, token, carrito y payload completo, y pay/cancel usan una clave estable por operacion+orden.

Los catalogos protegidos guardan la capability cifrada solo en `sessionStorage` bajo `ps-cap:{serial}` y la envian en `X-Store-Capability`. Un 401/403 publico limpia solo esa capability y vuelve al gate; no cierra la sesion autenticada del propietario.

## Formas de respuesta consumidas

El interceptor de `src/api/client.js` devuelve el `data` HTTP completo; las vistas acceden a `body.data`:

- `theme`: `data{ extra, has_password, colors, features, shipping_costs, payment_methods }`; la funcion de envio nacional se lee de `features.national_shipping` y la tarifa base de `shipping_costs.base`.
- `cart`: `data{ items, cart_token, total, count }` con items `{ id, product_id, product_name, price, quantity, addons, discount, product_image }`.
- `checkout`: `data.order_id` (el folio historico).
- `publicOrderDetail`: `data{ order, cliente, fecha, total, envio, items[], status, payment_status, payment_method, can_pay, can_cancel, can_submit_proof }` con items `{ name, qty, price, addons[] }`. No incluye telefono.
- `pay`: `data{ order_id, total, public_key, init_point, sandbox_init_point }`.
- `status`: `data.status` en `pending`, `paid`, `cancelled` o `refund_pending`; `payment_status=payment_exception` se muestra como excepcion y no habilita pago ni cancelacion.
- Cada producto: `{ id, nombre, precio, imagen, descripcion, categoria, activo, stock, variable }`; el detalle publico anade `aditivos[]` con `{ id, nombre, precio }`. Nunca expone `store_session`.

## Pruebas

- `tests/unit/public-store.test.js`: Vitest sobre token, capability por tienda, dinero, carrito, rutas tenant y claves deterministas.
- `tests/e2e/public-store.spec.js`: Playwright con `page.route`; cubre desktop/mobile, readiness fail-closed, COD solo local, relock sin logout owner, cancelacion con/sin reposicion, excepcion de cobro y QR local. No depende de backend, servicio QR externo ni Google Fonts.

```text
npm test                          # vitest de unidades
npm run build                     # build de produccion
npm run test:e2e                  # Playwright (desktop + mobile)
```

# Integracion MercadoPago Checkout Pro

FASE 5 convierte la preferencia MercadoPago historica en una preferencia real de Checkout Pro: el endpoint publico crea la preferencia remota con el access token del vendedor y el webhook la confirma comparando contra MercadoPago.

## Configuracion

```dotenv
MERCADOPAGO_WEBHOOK_SECRET=            # secreto firmado por el webhook
MERCADOPAGO_API_BASE_URL=https://api.mercadopago.com   # sandbox o produccion
MERCADOPAGO_NOTIFICATION_URL=          # opcional, si el tunel no coincide con app.url
FRONTEND_URL=https://tienda.example      # base de las back_urls
```

La cuenta se guarda en `mercadopagocuentas` con el `merchantId` verificado via `GET /users/me`. Restaurar una cuenta existente tambien la guarda en `mercadopago` con estado `1` y `payment_id`.

## Preferencia real

`App\Actions\MercadoPago\CreatePreference`:

- Carga los items del carrito de la orden y construye lineas `{ id, title, quantity, unit_price, currency_id: MXN }`.
- `unit_price = precio de la linea / cantidad`, nunca negativo; sin lineas validas responde 502 con mensaje claro.
- Envia `external_reference = folio de la orden` y `Idempotency-Key = sha256(folio)`; graba o actualiza `mercadopago` con la preferencia y estado `0`.

```text
POST {base}/checkout/preferences
  items, external_reference=folio, notification_url,
  back_urls.success|pending|failure -> FRONTEND_URL/store/{serial}/thanks?order={folio}
  auto_return=approved, binary_mode=true,
  statement_descriptor=nombretienda, payer.name, payer.phone
```

Respuesta del endpoint publico `pay` (requiere `X-Cart-Token` y una cuenta configurada; orden pagada o cancelada responde 422):

```json
{ "data": { "order_id": "FOLIO", "total": 150, "public_key": "PUB",
            "preference_id": "PREF", "init_point": "...", "sandbox_init_point": "..." } }
```

## Consulta de estado

`GET /api/v1/stores/{serial}/orders/{order}/status` devuelve `ping`, `pending`, `paid` o `cancelled` derivado de `order_state` y del estado guardado en `mercadopago`. En la pantalla de gracias el boton "Verificar pago" lo consulta y refresca el detalle cuando llega a `paid`.

## Webhook

`POST /api/v1/payments/webhook`:

1. Valida `X-Signature` = `ts={ts},v1={hmac}` y `X-Request-Id`; el manifest firmado es `id:{payload_id};request-id:{request_id};ts:{ts};` con `MERCADOPAGO_WEBHOOK_SECRET`. Firma invalida o sin secreto configurado responde 401.
2. Vuelve a consultar `GET /api/v1/payments/{payment_id}` con el token de la tienda duena del folio.
3. Confirma solo si estado `approved`, `external_reference` coincide, monto igual y `collector_id` pertenece a la cuenta (`merchantId`).
4. Actualiza la cuenta MercadoPago de la tienda, `mercadopago.payment_id`, `status=1` y `mercadopago.order_state=paid`, y mueve los carritos del folio a estado `3` dentro de una transaccion con `FOR UPDATE`.

Un desajuste de monto devuelve `status: ignored` sin tocar nada; un webhook duplicado es idempotente.

## Pruebas

- `tests/Feature/OnlinePaymentPreferenceTest.php`: preferencia real con `Http::fake`, alcance por token y tienda, cuenta ausente (422), ordenes canceladas/pagadas (422), y `pending -> paid` tras confirmar el webhook.
- `tests/Feature/PaymentSecurityTest.php`: alcance por tienda autenticada y reintento sin duplicados, firma, confirmacion remota y desajuste.

Las pruebas usan dobles `Http::fake`, ningun intento sale al exterior.
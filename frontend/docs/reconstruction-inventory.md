# Inventario de reconstrucción

## Compatibilidad preservada

- API base: `/api/v1`.
- Autenticación: Bearer token de Laravel Sanctum.
- Almacenamiento: `token` y `user` viven en `sessionStorage` o `localStorage` según “Recordarme”; también se preservan `remembered_email`, `onboarding_done`, `dash_theme` y `cart_token`.
- Tipos de usuario: `1` tienda, `3` repartidor y `4` cliente.
- Roles administrativos: `user.roles` puede contener `super-admin`.

## Rutas recuperadas

| Área | Rutas |
| --- | --- |
| Públicas | `/`, `/store/:serial`, `/marketplace`, `/book/:serial`, `/p/:slug` |
| Acceso | `/login`, `/register`, `/forgot-password` |
| Tienda | `/dashboard`, `/dashboard/products`, `/dashboard/orders`, `/dashboard/pos`, `/dashboard/appointments`, `/dashboard/crm`, `/dashboard/analytics`, `/dashboard/settings` |
| Repartidor | `/delivery`, `/delivery/stores`, `/delivery/profile` |
| Cliente | `/customer` |
| Administración | `/dashboard/admin-users`, `/dashboard/admin-site-settings`, `/dashboard/subscriptions` |

El listado completo se conserva en `src/router/index.js`; los módulos todavía no migrados muestran una pantalla de transición con acceso a la versión compilada.

## Estado por fase

- FASE 1: infraestructura, autenticación, layouts y dashboard completados.
- FASE 2: Productos completado en listado, alta, edición, eliminación, imágenes, categorías y extras.
- Evidencia y contrato de Productos: `docs/products-reconstruction.md`.

## Orden recomendado

1. Autenticación y dashboard base.
2. Productos, carga de imágenes y categorías. Completado.
3. POS e historial. Recomendado para FASE 3.
4. Pedidos y cargos adicionales.
5. Tienda pública, carrito y checkout.
6. Configuración, constructor y onboarding.
7. CRM, agenda, delivery, lealtad y chat.
8. Analíticas, administración e integraciones.

## Riesgos detectados en backend

- Las rutas API desconocidas actualmente pueden devolver el HTML del SPA con estado 200.
- Varios endpoints protegidos no aplican middleware de rol o permiso.
- El detalle de pedidos no siempre valida pertenencia a la tienda.
- El detalle público de pedidos no valida el `cart_token`.
- El POS acepta identificadores de productos sin validar siempre su tienda; el carrito ya restringe producto y extras activos a la tienda solicitada, pero no reserva stock.
- La respuesta administrativa de ajustes incluye la contraseña SMTP.
- Productos alimenta POS, catálogo público, cupones, QR y apartados; varios de esos consumidores no validan tenant o stock en backend.

Estos problemas deben corregirse en backend; ocultar botones en Vue no constituye autorización.

## Ajustes aplicados durante la reconstrucción

- Los tokens normales caducan a las 12 horas y permanecen en `sessionStorage`.
- Los tokens con “Recordarme” caducan a los 30 días y permanecen en `localStorage`.
- Login y `/user` cargan explícitamente los roles para que los guards administrativos funcionen.
- Las personalizaciones visuales ya no bloquean el render si una API tarda o falla.
- Productos normaliza sus dos formas de stock, aplica el límite del plan de forma transaccional, conecta permisos frontend/backend y evita exponer `store_session` públicamente.
- Una migración asigna el rol `store-owner` a propietarios legacy sin rol para evitar bloqueos al activar permisos.
- Todos los flujos de emisión de token caducan a las 12 horas, excepto “Recordarme” que conserva 30 días.

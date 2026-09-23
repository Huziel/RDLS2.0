# Plan maestro de recuperación RDLS2.0 (Fases 6–14)

**Punto de partida verificado:** en la consulta GitHub del 22-09-2026, `recuperacion` estaba **6 commits delante de `main`**; existen en código la FASE 5 (tienda pública individual, Checkout Pro, cancelación/reposición), además de runbook de despliegue FASE 6; **esto no significa producción desplegada**. NO repetir fases 1–5.

## Puerta de entrada obligatoria — FASE 6: auditoría maestra + P0

**Estado: EJECUTADA y CERRADA en código el 2026-09-22 (ver `FASE-6-reporte.md`), con NO CONFIRMADO explícitos.** Nada fue desplegado a producción; cambios sin commit/push en `recuperacion` (HEAD `2985648`).

### Estado real al cierre (FASE 6)

- IMPLEMENTADO EN CÓDIGO + PROBADO LOCALMENTE (SQLite `:memory:`, **110 tests / 622 assertions**, Pint y `php -l` limpios): lotes 6A, 6B y 6C de la auditoría P0 — tenant en `emitOrder`, RBAC Spatie por ruta (`role_or_permission`), verificación global solo superadmin (ruta del dueño eliminada), datos sensibles ocultos (código de verificación, `foto_id`/`foto_domicilio`), `/api` → JSON 404, `completeOrder` 503 por flag, `assignment_mode` pool/direct, evidencia fail-closed, GPS con IDOR cerrado, `confirmPayment` y webhook MP idempotentes, cancelación con envío terminal `status='4'`, migraciones 000001/000002 con preflight, TOCTOU `publicCancel` cerrado.
- NO CONFIRMADO (sin prometer cierre): concurrencia real (sin MariaDB 11.8/Docker en el entorno; tests secuenciales SQLite monoconexión); migraciones sin ejecutar contra BD real ni clon (preflight de duplicados aborta el despliegue por diseño); suite JS (`npm test`/`test:e2e`/`build`) no ejecutada (scope backend); bundle legacy `public/` sigue consumiendo contrato antiguo (`emitShipping({})` → pool; UI se corrige en FASE 7).
- P2 registrados para FASE 8/9 (lista cerrada en `MATRIZ-BRECHAS.md`): `availableOrders` PII sin paginación, `attachStore` ID numérico, `updateProfile` URLs sin validar storage, `myOrders` por `X-Cart-Token`, `QrController` open redirect, toggles no idempotentes, POST `/api/` → 419 CSRF en producción, flag OTP, índices únicos `datospersonales.idLog` y `anexosdeliver`.
- Decisiones de negocio aprobadas registradas en `FASE-6-reporte.md` (asignación híbrida pool/direct; cancelación B; pago B+A; cliente B + OTP; wallet A).
- La matríz `función -> captura -> ruta frontend -> endpoint -> tabla -> permisos -> tests -> estado -> brecha` quedó materializada en `MATRIZ-BRECHAS.md` (filas reales con ruta/archivo/línea y pruebas).

### Alcance documentado (declarado al inicio de la fase)

Objetivo: cruzar tres Word/captura con `frontend/src/router/index.js`, `routes/api.php`, `frontend/docs`, Laravel y JS compilado. Generar matriz `función -> captura -> ruta frontend -> endpoint -> tabla -> permisos -> tests -> estado -> brecha`.

**P0**: cerrar filtración del código de entrega, comprobación de tenant al emitir, elegibilidad al aceptar, exclusividad de verificación global superadmin y acceso a documentos; impedir confirmación manual sobre cancelaciones/pagos ya confirmados; no exponer contraseña SMTP; evitar `unknown API -> 200 SPA`. En paralelo revisión de guard de BD, permisos y QR externo.

Subagentes: `ruta-evidencia`, `ruta-arqueologo`, `ruta-seguridad`, `ruta-arquitecto`; backend ejecuta fixes aprobados; QA valida con fixtures.

Entregables: `MATRIZ-BRECHAS.md` real, ADR de reglas ambiguas, lista P0, pruebas backend para acceso cruzado y códigos ocultos, reporte de fase. **Gate**: ninguna nueva UI de delivery mientras sigan P0 abiertos.

## FASE 7: Marketplace general multitienda

Dependencias: tienda individual de FASE 5, contrato público de producto, reglas de carrito. Construir portada Marketplace, búsqueda global, categorías, lista de tiendas, detalle global de producto, ficha vendedor y panel lateral agrupado por tienda; cada grupo navega a carrito propio. Conservar y probar el token por sesión y `storeSerial`; ningún producto de tienda A debe aparecer o cobrarse bajo B. Contrastar colores/UX con Word pp.1–2.

Entrega: rutas `/marketplace*` reales, API/read model de catálogo con paginación, filtros y seguridad, responsive desktop/móvil, E2E cambio de tienda y carritos segregados. NO refactorizar checkout individual salvo bug confirmado.

## FASE 8: Pedidos vendedor + cliente

Dependencias: marketplace, checkout, auth cliente tipo 4. Reconstruir `/dashboard/orders`: tabla, detalle, estados, confirmación manual SOLO de efectivo/transferencia conforme regla validada; bloqueo de pagos repetidos/cancelados, control de tenant, WhatsApp sin exponer código de entrega, cargos si existían. Portal cliente `/customer`: alta/acceso y mis pedidos asociados a identidad verificada; pedido público basado en token no prueba propiedad de cuenta. Actualizar estado de pago sin inventar cobros.

Entrega: estados transaccionales documentados, tests idempotencia/tenant, comparativa original vs ampliación MP/cancelación, E2E vendedores/clientes.

## FASE 9: Delivery completo y autorización documental

Dependencias: FASE 6 P0, FASE 8 pedido/cliente. UI rider: registro, perfil y carga documental privada, mis tiendas/serial, mapa pedidos, aceptar, activo, ruta, historial, billetera. UI dueño: código de vinculación, vínculos, bloquear/desvincular, emitir y asignar según política aclarada. UI superusuario: revisar documentos, verificar/revocar; solo este rol cambia la autorización global. Código 3 dígitos de cliente autenticado, generado con RNG seguro, almacenado hashed si procede, expiración/intent-limit/uso único y no retornado al repartidor. Bloqueo transaccional de aceptación y compleción, aislamiento de tiendas, proteger ubicaciones y documentos.

Entrega: pruebas de entrega concurrente, doble completado, fraude con código, acceso a documentos; E2E 3 roles; documentación de política de asignación. No mover saldo wallet dos veces.

## FASE 10: Configuración tienda + onboarding + constructor visual

Dependencias: auth/tenant, frontend público. Config de tienda por pestañas (datos/logo/redes/transferencia, colores/temas, dirección/mapa, entrega/precios, MercadoPago, horarios/disponibilidad, catálogo con contraseña). Constructor de bloques: textos, productos, imágenes, fondos, video, estilos y orden con vistas previas y guardado. Sanitizar HTML (XSS), validar uploads y rutas. Separar `StoreSettings` de ajustes globales superadmin.

Entrega: `/dashboard/settings`, `/dashboard/builder`, `/onboarding`, vista previa comparable con Word pp.15–20, E2E cambios que repercuten en tienda pública.

## FASE 11: Cupones, QR y Compartir, apartados/lealtad

Dependencias: productos/tienda/pedidos. Cupones con límite de usos, fechas, porcentajes o monto y restricciones por tienda; no confiar en importes cliente. QR para tienda/menú/producto con enlaces limpios; NO filtrar `X-Cart-Token`, códigos de entrega, URLs con secretos ni datos privados a servicio QR externo. Auditoría de apartados y ledger de lealtad: reconstruir SOLO si el código/Word respaldan la función, documentar reglas faltantes.

Entrega: vistas administrativas y públicas comprobadas; E2E cupones y QR; pruebas anticruce tenant y consumo duplicado.

## FASE 12: CRM, agenda, multimedia, trueques, chat

Dependencias: pedidos/usuario/tenant. CRM lista y detalle clientes y sincronización idempotente de pedidos; agenda calendario y disponibilidad de tienda/citas; multimedia links/uploads privados; trueques/chat según contratos comprobados, no inventar escrows ni mensajes inexistentes. Confirmar reglas no visibles en capturas con el usuario.

Entrega: módulos reales en lugar de `ModulePendingView`, tests de permisos y datos de cliente, E2E rutas principales.

## FASE 13: Analytics, publicidad IA, administración global

Dependencias: pedidos y ventas recuperados. Analytics basado en datos reales y filtrado por tenant (ingresos, órdenes, promedio, productos, horario y meses). Publicidad IA debe **revisarse/diseñarse con usuario**; la captura no garantiza generación efectiva. Superusuario: usuarios, revisión documental rider, branding global, landing, plantillas y páginas personalizadas, ajustes SMTP sin devolver contraseña, suscripciones/planes e integraciones; revisar autorización y multi-tenant.

Entrega: pantallas, APIs auditadas, sanitización, tests y revisión de privilegios.

## FASE 14: Aceptación integral y preparación despliegue (NO despliegue automático)

E2E full por roles propietario, superadmin, repartidor, cliente invitado y cliente autenticado. Flujos marketplace -> carrito por tienda -> checkout -> pedido -> pago manual/MP sandbox -> cancelación/restock -> delivery -> verificación 3 dígitos -> informes; regresión POS y lealtad. Accesibilidad mobile/desktop, rendimiento, logs/observabilidad, reportes de errores. DDL/locks en MariaDB 11.8.x **desechable**, no inferir compatibilidad por MySQL 8. Revisar migraciones 000001–000005 e índices contra copia reciente **sanitizada y reconciliada**. Revisar git diff secretos; backup/restauración ensayados; CI si autorizado. Producir runbook y checklist GO/NO-GO; el propietario decide si/cuándo desplegar.

## Protocolo obligatorio por fase

1. BASELINE: rama/HEAD limpio y documentación fuente; inventario de rutas y evidencias.
2. DISEÑO: contratos, matriz de permisos y estados, riesgos, ADR para ambigüedad.
3. PRUEBAS QUE FALLAN: tests del bug o contrato antes de implementar cuando sea viable.
4. IMPLEMENTACIÓN acotada: un equipo escritura por módulo; paralelizar solo auditorías de lectura.
5. TEST: Vitest, Playwright desktop/móvil con mocks, Laravel SQLite memory; MariaDB desechable para concurrency/DDL.
6. REVISIÓN: seguridad, código, UX vs capturas, secretos, regresión.
7. REPORTE: archivos, cambio de contrato, cobertura real, bloqueos, incidentes, git diff.
8. COMMIT pequeño en `recuperacion` con mensaje `Fase N: ...`. Push requiere aprobación explícita.

## Mínimo de cierre de fase

- Ningún P0 abierto en el módulo; tenant backend verificado por tests negativos.
- Capturas/Word comparados con pantalla desktop/móvil; funciones de extensión etiquetadas.
- Sin escrituras ni pruebas en producción; original `public/` intacto.
- Conteos exactos de tests ejecutados y logs de resultados, no cifras inventadas.
- Migraciones nuevas probadas solo en clon desechable.
- `docs/recovery/MATRIZ-BRECHAS.md` y reporte de fase actualizados.

---

## Estado de evidencia FASE 6

Fecha: 2026-09-22 · Rama: `recuperacion` (HEAD `2985648`, cambios sin commit/push) · Suite: **110 tests / 622 assertions** (SQLite `:memory:`), Pint focalizado y `php -l` limpios.

- IMPLEMENTADO EN CÓDIGO y PROBADO LOCALMENTE: lotes 6A, 6B y 6C (detalle en `FASE-6-reporte.md`).
- NO CONFIRMADO: concurrencia real (sin MariaDB 11.8/Docker en el entorno), migraciones sobre BD real/clon, suite JS, compatibilidad del bundle legacy (UI fuente se corrige en FASE 7).
- Nada desplegado en producción; sin commit ni push (pendiente autorización del dueño).

Firmado: `ruta-seguridad` · `ruta-qa` · `ruta-review` — 2026-09-22.

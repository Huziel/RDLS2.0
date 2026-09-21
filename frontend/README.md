# Nuevo frontend

Reconstrucción progresiva del frontend Vue. La aplicación compilada actual permanece en `../public` y este proyecto genera su salida únicamente en `frontend/dist`.

## Desarrollo

```bash
npm install
npm run dev
```

Laravel debe ejecutarse en `http://127.0.0.1:8000`; Vite redirige `/api` hacia ese servidor. Se puede cambiar con `VITE_BACKEND_URL`.

## Verificación

```bash
npm test
npm run test:e2e
npm run build
```

Las pruebas E2E usan Microsoft Edge instalado en el sistema y simulan las APIs que ejercitan. Cualquier endpoint no simulado apunta a `127.0.0.1:9`; no escriben en la base de datos.

Los source maps están desactivados por defecto. Para generarlos sin enlazarlos desde los bundles, usa `VITE_SOURCEMAP=true` y almacénalos fuera del directorio que se publique.

## Estado

- Implementado: cliente API, autenticación, persistencia compatible, guards, layout de acceso, login responsivo, dashboard y módulo Productos.
- Productos incluye listado, búsqueda, categoría/estado, paginación, alta, edición, eliminación, imágenes y extras.
- Inventariado: todas las rutas y contratos principales.
- Pendiente: formularios de registro/recuperación y módulos operativos.

Consulta `docs/reconstruction-inventory.md` para el orden de migración.

import { expect, test } from '@playwright/test'

const storeUser = {
  id: 1,
  name: 'pos@example.com',
  type: '1',
  roles: ['store-owner'],
  permissions: ['dashboard.view', 'pos.use', 'pos.history'],
}

const catalog = [
  { id: 1, nombre: 'Café clásico', precio: 35, categoria: 'Bebidas', activo: true, stock: 8, codigo_barras: '75001' },
  { id: 2, nombre: 'Taza artesanal', precio: 120, categoria: 'Accesorios', activo: true, stock: 3, codigo_barras: '75002' },
]

function historyOrder(overrides = {}) {
  return {
    id: 90,
    noOrder: 'POS-ANTERIOR',
    nombre: 'Cliente historial',
    telefono: '5551002000',
    fecha: '2026-09-21 10:00:00',
    estado: 2,
    total: 70,
    extra: 0,
    descuento: 0,
    tipoPago: 1,
    details: [{ id: 901, productoId: 1, cantidad: 2, nameProd: 'Café clásico', precioBruto: 35, precioNeto: 70 }],
    ...overrides,
  }
}

async function mockSession(page, user = storeUser) {
  await page.addInitScript((sessionUser) => {
    localStorage.setItem('token', 'pos-test-token')
    localStorage.setItem('user', JSON.stringify(sessionUser))
    localStorage.setItem('onboarding_done', 'true')
  }, user)
  await page.route('**/api/v1/public/site-settings', (route) => route.fulfill({ json: { data: { site_name: 'Ruta de la Seda' } } }))
  await page.route('**/api/v1/user', (route) => route.fulfill({ json: { data: user } }))
  await page.route('**/api/v1/store', (route) => route.fulfill({ json: { data: { serial: 'POS-01', logo: null } } }))
  await page.route('**/api/v1/store/extra', (route) => route.fulfill({ json: { data: { nombre_tienda: 'Tienda POS' } } }))
  await page.route('**/api/v1/store/colors', (route) => route.fulfill({ json: { data: null } }))
  await page.route('**/api/v1/my-subscription', (route) => route.fulfill({ json: { data: { plan_name: 'Profesional', modules: ['pos'] } } }))
  await page.route('**/api/v1/dashboard/stats', (route) => route.fulfill({ json: { data: {} } }))
}

async function mockPos(page, options = {}) {
  const availableProducts = options.catalog || catalog
  const state = {
    orders: [],
    history: [historyOrder()],
    nextOrderId: 10,
    nextDetailId: 100,
    payment: null,
    historyQuery: null,
  }

  await page.route('**/api/v1/products**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/search-barcode')) {
      const product = availableProducts.find((item) => item.codigo_barras === url.searchParams.get('code'))
      return product
        ? route.fulfill({ json: { data: product } })
        : route.fulfill({ status: 404, json: { message: 'Producto no encontrado.' } })
    }
    const pageNumber = Number(url.searchParams.get('page') || 1)
    const perPage = Number(url.searchParams.get('per_page') || 20)
    return route.fulfill({ json: {
      data: availableProducts.slice((pageNumber - 1) * perPage, pageNumber * perPage),
      meta: { current_page: pageNumber, last_page: Math.ceil(availableProducts.length / perPage), total: availableProducts.length },
    } })
  })

  await page.route('**/api/v1/pos/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname
    const method = request.method()

    if (path === '/api/v1/pos/orders' && method === 'GET') {
      return route.fulfill({ json: { data: state.orders } })
    }
    if (path === '/api/v1/pos/orders' && method === 'POST') {
      const payload = request.postDataJSON()
      const order = {
        id: state.nextOrderId++,
        noOrder: `POS-${state.nextOrderId}`,
        nombre: payload.nombre,
        telefono: payload.telefono,
        fecha: '2026-09-22 10:00:00',
        estado: 0,
        total: 0,
        extra: 0,
        descuento: 0,
        tipoPago: 0,
        details: [],
      }
      state.orders.unshift(order)
      return route.fulfill({ status: 201, json: { data: order, message: 'Orden creada.' } })
    }
    if (/\/api\/v1\/pos\/orders\/\d+\/products$/.test(path) && method === 'POST') {
      const order = state.orders.find((item) => item.id === Number(path.split('/')[5]))
      const payload = request.postDataJSON()
      const product = availableProducts.find((item) => item.id === payload.producto_id)
      const detail = {
        id: state.nextDetailId++,
        productoId: product.id,
        cantidad: payload.cantidad,
        nameProd: product.nombre,
        precioBruto: product.precio,
        precioNeto: product.precio * payload.cantidad,
      }
      order.details.push(detail)
      return route.fulfill({ json: { data: detail, message: 'Producto agregado.' } })
    }
    if (/\/api\/v1\/pos\/orders\/\d+\/products\/\d+$/.test(path) && method === 'PUT') {
      const parts = path.split('/')
      const order = state.orders.find((item) => item.id === Number(parts[5]))
      const detail = order.details.find((item) => item.id === Number(parts[7]))
      detail.cantidad = request.postDataJSON().cantidad
      detail.precioNeto = detail.precioBruto * detail.cantidad
      return route.fulfill({ json: { data: detail } })
    }
    if (/\/api\/v1\/pos\/orders\/\d+\/save$/.test(path) && method === 'POST') {
      const order = state.orders.find((item) => item.id === Number(path.split('/')[5]))
      order.extra = request.postDataJSON().extra || 0
      order.total = order.details.reduce((sum, item) => sum + item.precioNeto, 0) + order.extra
      order.estado = 1
      return route.fulfill({ json: { data: order, message: 'Orden guardada.' } })
    }
    if (/\/api\/v1\/pos\/orders\/\d+\/pay$/.test(path) && method === 'POST') {
      const id = Number(path.split('/')[5])
      const order = state.orders.find((item) => item.id === id)
      const payload = request.postDataJSON()
      state.payment = payload
      const paymentType = { efectivo: 1, tarjeta: 2, transferencia: 3 }[payload.tipo_pago]
      const paid = { ...order, id: 1000 + order.id, estado: 2, tipoPago: paymentType }
      state.history.unshift(paid)
      state.orders = state.orders.filter((item) => item.id !== id)
      return route.fulfill({ json: { data: paid, idempotent: false, message: 'Orden pagada exitosamente.' } })
    }
    if (path === '/api/v1/pos/loyalty/check' && method === 'POST') {
      return route.fulfill({ json: { data: { registered: true, client_name: 'Cliente leal', points: 100, can_redeem: true, minimum_to_redeem: 10, pesos_per_point: 10 } } })
    }
    if (path === '/api/v1/pos/history' && method === 'GET') {
      state.historyQuery = Object.fromEntries(url.searchParams)
      const rows = state.history.filter((item) => !url.searchParams.get('payment') || item.tipoPago === ({ efectivo: 1, tarjeta: 2, transferencia: 3 })[url.searchParams.get('payment')])
      const total = rows.reduce((sum, item) => sum + item.total, 0)
      return route.fulfill({ json: {
        data: rows,
        current_page: 1,
        last_page: 1,
        total: rows.length,
        stats: { count: rows.length, total, average: rows.length ? total / rows.length : 0, cash: rows.filter((item) => item.tipoPago === 1).length, card: rows.filter((item) => item.tipoPago === 2).length, transfer: rows.filter((item) => item.tipoPago === 3).length },
      } })
    }
    return route.fulfill({ status: 404, json: { message: `Mock no configurado: ${method} ${path}` } })
  })

  return state
}

test.beforeEach(async ({ page }) => {
  await mockSession(page)
})

test('completes a POS sale and renders its printable ticket', async ({ page }) => {
  const state = await mockPos(page)
  await page.goto('/dashboard/pos')
  await page.getByRole('button', { name: '+ Nueva orden' }).click()
  await expect(page.getByText('Orden creada.')).toBeVisible()
  await page.getByRole('button', { name: /Café clásico/ }).click()
  await expect(page.locator('.pos-line').getByText('Café clásico')).toBeVisible()
  await page.getByRole('button', { name: 'Guardar orden' }).click()
  await expect(page.getByText('Orden guardada y lista para cobrar.')).toBeVisible()
  await page.getByLabel('Método de pago').selectOption('tarjeta')
  await page.getByRole('button', { name: 'Cobrar' }).click()

  await expect(page.getByRole('dialog', { name: 'Ticket de venta' })).toContainText('$35.00')
  expect(state.payment).toEqual({ tipo_pago: 'tarjeta', loyalty_points: 0 })
  expect(state.history[0].tipoPago).toBe(2)
})

test('adds by barcode and sends loyalty points only with the final payment', async ({ page }) => {
  const state = await mockPos(page)
  await page.goto('/dashboard/pos')
  await page.getByLabel('Teléfono para lealtad').fill('5551002000')
  await page.getByRole('button', { name: '+ Nueva orden' }).click()
  await page.getByPlaceholder('Escanear código').fill('75002')
  await page.getByRole('button', { name: 'Agregar' }).click()
  await expect(page.locator('.pos-line').getByText('Taza artesanal')).toBeVisible()
  await page.getByRole('button', { name: 'Guardar orden' }).click()
  await page.getByRole('button', { name: 'Consultar puntos' }).click()
  await expect(page.getByText(/Cliente leal.*100 puntos/)).toBeVisible()
  await page.getByLabel('Puntos a canjear').fill('25')
  await expect(page.getByLabel('Puntos a canjear')).toHaveValue('20')
  await page.getByRole('button', { name: 'Cobrar' }).click()

  await expect(page.getByRole('dialog', { name: 'Ticket de venta' })).toBeVisible()
  expect(state.payment.loyalty_points).toBe(20)
})

test('filters history and expands a sale detail', async ({ page }) => {
  const state = await mockPos(page)
  await page.goto('/dashboard/pos/history')
  await expect(page.getByRole('heading', { name: 'Historial POS' })).toBeVisible()
  await expect(page.getByText('$70.00').first()).toBeVisible()
  await page.getByLabel('Desde').fill('2026-09-01')
  await page.getByLabel('Hasta').fill('2026-09-30')
  await page.getByLabel('Método').selectOption('efectivo')
  await page.getByRole('button', { name: 'Filtrar' }).click()
  await expect.poll(() => state.historyQuery).toMatchObject({ from: '2026-09-01', to: '2026-09-30', payment: 'efectivo' })
  await page.getByRole('button', { name: /POS-ANTERIOR/ }).click()
  await expect(page.locator('.pos-history-detail')).toContainText('2 × Café clásico')
})

test('keeps the POS workspace inside a mobile viewport', async ({ page }) => {
  await mockPos(page)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/dashboard/pos')
  await expect(page.getByRole('heading', { name: 'Punto de venta' })).toBeVisible()
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
})

test('loads every product page for an unlimited catalog', async ({ page }) => {
  const largeCatalog = Array.from({ length: 101 }, (_, index) => ({
    id: index + 1,
    nombre: `Producto ${String(index + 1).padStart(3, '0')}`,
    precio: 10,
    categoria: 'General',
    activo: true,
    stock: 2,
    codigo_barras: `CODE-${index + 1}`,
  }))
  await mockPos(page, { catalog: largeCatalog })
  await page.goto('/dashboard/pos')
  await page.getByPlaceholder('Buscar por nombre, categoría o código').fill('Producto 101')
  await expect(page.getByRole('button', { name: /Producto 101/ })).toBeVisible()
})

test('redirects users without POS permission to their authorized dashboard route', async ({ page }) => {
  const user = { ...storeUser, permissions: ['dashboard.view'] }
  await page.addInitScript((sessionUser) => localStorage.setItem('user', JSON.stringify(sessionUser)), user)
  await page.unroute('**/api/v1/user')
  await page.route('**/api/v1/user', (route) => route.fulfill({ json: { data: user } }))
  await mockPos(page)
  await page.goto('/dashboard/pos')
  await expect(page).toHaveURL(/\/dashboard$/)
})

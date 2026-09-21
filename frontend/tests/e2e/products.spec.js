import { expect, test } from '@playwright/test'

const storeUser = {
  id: 1,
  name: 'tienda@example.com',
  type: '1',
  roles: ['store-owner'],
  permissions: ['products.read', 'products.create', 'products.update', 'products.delete'],
}

const products = [
  { id: 1, nombre: 'Café clásico', precio: '35.00', imagen: null, categoria: 'Bebidas', activo: true, stock: 8 },
  { id: 2, nombre: 'Taza artesanal', precio: '120.00', imagen: null, categoria: 'Accesorios', activo: false, stock: 2 },
  { id: 3, nombre: 'Café reserva', precio: '85.00', imagen: null, categoria: 'Bebidas', activo: true, stock: 4 },
]

async function mockSession(page, user = storeUser) {
  await page.addInitScript((sessionUser) => {
    localStorage.setItem('token', 'product-test-token')
    localStorage.setItem('user', JSON.stringify(sessionUser))
    localStorage.setItem('onboarding_done', 'true')
  }, user)
  await page.route('**/api/v1/public/site-settings', (route) => route.fulfill({ json: { data: { site_name: 'Ruta de la Seda' } } }))
  await page.route('**/api/v1/user', (route) => route.fulfill({ json: { data: user } }))
}

async function mockDashboardShell(page, options = {}) {
  await page.route('**/api/v1/store', (route) => route.fulfill({ json: { data: { serial: 'DEMO-01', logo: null } } }))
  await page.route('**/api/v1/store/extra', (route) => route.fulfill({ json: { data: { nombre_tienda: 'Tienda Demo' } } }))
  await page.route('**/api/v1/store/colors', (route) => route.fulfill({ json: { data: null } }))
  await page.route('**/api/v1/my-subscription', (route) => route.fulfill({
    json: { data: {
      plan_name: 'Profesional',
      max_products: Object.hasOwn(options, 'maxProducts') ? options.maxProducts : null,
      current_products: options.currentProducts ?? products.length,
      modules: ['products'],
    } },
  }))
}

async function mockProducts(page, options = {}) {
  const state = { created: null, updated: null, deleted: null, addonUpdated: null }
  await page.route('**/api/v1/categories', (route) => route.fulfill({ json: { data: ['Accesorios', 'Bebidas'] } }))
  await page.route('**/api/v1/upload/image', (route) => route.fulfill({
    json: { data: { url: 'https://cdn.test/product.png' }, message: 'Imagen subida exitosamente.' },
  }))

  await page.route('**/api/v1/products**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname
    const method = request.method()

    if (path === '/api/v1/products' && method === 'GET') {
      if (options.listStatus) return route.fulfill({ status: options.listStatus, json: { message: options.listMessage || 'Error de productos.' } })
      if (options.listError) return route.fulfill({ status: 500, json: { message: 'No fue posible consultar productos.' } })
      const search = (url.searchParams.get('search') || '').toLocaleLowerCase('es')
      const active = url.searchParams.get('active')
      const category = url.searchParams.get('category')
      const pageNumber = Number(url.searchParams.get('page') || 1)
      let data = products.filter((product) => product.nombre.toLocaleLowerCase('es').includes(search))
      if (active !== null) data = data.filter((product) => String(Number(product.activo)) === active)
      if (category && category !== 'all') data = data.filter((product) => product.categoria === category)
      const defaultListing = !search && active === null && (!category || category === 'all')
      if (defaultListing) data = pageNumber === 2 ? [products[2]] : products.slice(0, 2)
      return route.fulfill({
        json: {
          data,
          meta: {
            current_page: pageNumber,
            last_page: defaultListing ? 2 : 1,
            per_page: 20,
            total: data.length,
            from: data.length ? 1 : null,
            to: data.length,
          },
        },
      })
    }

    if (path === '/api/v1/products' && method === 'POST') {
      state.created = request.postDataJSON()
      return route.fulfill({ status: 201, json: { data: { id: 9, ...state.created }, message: 'Producto creado exitosamente.' } })
    }

    if (/\/api\/v1\/products\/1$/.test(path) && method === 'GET') {
      if (options.detail404) return route.fulfill({ status: 404, json: { message: 'Producto no encontrado.' } })
      return route.fulfill({
        json: {
          data: {
            ...products[0],
            descripcion: 'Tueste medio',
            variable: '',
            stock: { cantidad: 8, type: null },
            codigo_barras: null,
            imagenes: [],
            aditivos: [],
          },
        },
      })
    }

    if (/\/api\/v1\/products\/1$/.test(path) && method === 'PUT') {
      state.updated = request.postDataJSON()
      return route.fulfill({ json: { data: { id: 1, ...state.updated }, message: 'Producto actualizado.' } })
    }

    if (/\/api\/v1\/products\/\d+$/.test(path) && method === 'DELETE') {
      state.deleted = Number(path.split('/').pop())
      return route.fulfill({ json: { message: 'Producto eliminado.' } })
    }

    if (/\/api\/v1\/products\/1\/addons$/.test(path) && method === 'GET') {
      return route.fulfill({ json: { data: [{ id: 11, nombre: 'Leche vegetal', precio: 10, categoria: 'Leches', descripcion: 'Sin lactosa', activo: true, stock: 4 }] } })
    }

    if (/\/api\/v1\/products\/1\/addons\/11$/.test(path) && method === 'PUT') {
      state.addonUpdated = request.postDataJSON()
      return route.fulfill({ json: { data: { id: 11, ...state.addonUpdated }, message: 'Aditivo actualizado.' } })
    }

    return route.fulfill({ status: 404, json: { message: 'Mock no configurado.' } })
  })
  return state
}

test.beforeEach(async ({ page }) => {
  await mockSession(page)
  await mockDashboardShell(page)
})

test('lists, searches, filters and paginates products', async ({ page }) => {
  await mockProducts(page)
  await page.goto('/dashboard/products')
  await expect(page.getByRole('heading', { name: 'Productos' })).toBeVisible()
  const visibleResults = page.locator('.product-table:visible, .product-card-list:visible')
  await expect(visibleResults.getByText('Café clásico')).toBeVisible()
  await expect(visibleResults.getByText(/^(?:Stock: )?8$/)).toBeVisible()

  await page.getByPlaceholder('Buscar producto...').fill('reserva')
  await expect(visibleResults.getByText('Café reserva')).toBeVisible()
  await expect(visibleResults.getByText('Taza artesanal')).not.toBeVisible()

  await page.getByPlaceholder('Buscar producto...').fill('')
  await page.getByLabel('Estado').selectOption('0')
  await expect(visibleResults.getByText('Taza artesanal')).toBeVisible()
  await expect(visibleResults.getByText('Café clásico')).not.toBeVisible()

  await page.getByLabel('Estado').selectOption('')
  await page.getByLabel('Categoría').selectOption('Bebidas')
  await expect(visibleResults.getByText('Café clásico')).toBeVisible()
  await expect(visibleResults.getByText('Taza artesanal')).not.toBeVisible()
  await page.getByLabel('Categoría').selectOption('all')
  await page.getByRole('button', { name: 'Siguiente' }).click()
  await expect(visibleResults.getByText('Café reserva')).toBeVisible()
  await expect(page.getByText('Página 2 de 2')).toBeVisible()
})

test('opens an existing product and its confirmed addons', async ({ page }) => {
  await mockProducts(page)
  await page.goto('/dashboard/products')
  await page.getByRole('link', { name: 'Editar' }).first().click()
  await expect(page).toHaveURL(/\/dashboard\/products\/1\/edit$/)
  await expect(page.getByLabel('Nombre *')).toHaveValue('Café clásico')
  await expect(page.getByText('Leche vegetal')).toBeVisible()
})

test('validates, uploads and creates a product with mocked writes', async ({ page }) => {
  const state = await mockProducts(page)
  await page.goto('/dashboard/products/create')
  await page.getByRole('button', { name: 'Crear producto' }).click()
  await expect(page.getByText('El nombre es obligatorio.')).toBeVisible()
  await expect(page.getByText('El precio es obligatorio.')).toBeVisible()

  await page.getByLabel('Nombre *').fill('Producto nuevo')
  await page.getByLabel('Precio *').fill('49.90')
  await page.getByLabel('Categoría').fill('Bebidas')
  await page.getByLabel('Existencias').fill('6')
  await page.locator('.product-image-field input[type="file"]').setInputFiles({
    name: 'producto.png',
    mimeType: 'image/png',
    buffer: Buffer.from('fake-image'),
  })
  await expect(page.locator('.image-preview img')).toHaveAttribute('src', 'https://cdn.test/product.png')
  await page.getByRole('button', { name: 'Crear producto' }).click()
  await expect(page).toHaveURL(/\/dashboard\/products\?created=1$/)
  expect(state.created).toMatchObject({ nombre: 'Producto nuevo', precio: 49.9, categoria: 'Bebidas', stock: 6, imagen: 'https://cdn.test/product.png' })
})

test('updates an existing product without real writes', async ({ page }) => {
  const state = await mockProducts(page)
  await page.goto('/dashboard/products/1/edit')
  await page.getByLabel('Nombre *').fill('Café actualizado')
  await page.getByRole('button', { name: 'Actualizar producto' }).click()
  await expect(page).toHaveURL(/\/dashboard\/products\?updated=1$/)
  expect(state.updated).toMatchObject({ nombre: 'Café actualizado', precio: 35, stock: 8 })
})

test('updates all confirmed addon fields with a mocked write', async ({ page }) => {
  const state = await mockProducts(page)
  await page.goto('/dashboard/products/1/edit')
  await page.getByRole('button', { name: 'Editar' }).click()
  await page.getByLabel('Nombre del extra').fill('Leche de avena')
  await page.getByLabel('Precio adicional').fill('12')
  await page.getByLabel('Categoría').last().fill('Alternativas')
  await page.getByLabel('Existencias').last().fill('7')
  await page.getByLabel('Descripción').last().fill('Bebida vegetal')
  await page.getByRole('button', { name: 'Actualizar extra' }).click()
  await expect.poll(() => state.addonUpdated).not.toBeNull()
  expect(state.addonUpdated).toEqual({
    nombre: 'Leche de avena',
    precio: 12,
    categoria: 'Alternativas',
    descripcion: 'Bebida vegetal',
    activo: true,
    stock: 7,
  })
})

test('deletes only after confirmation using a mocked endpoint', async ({ page }) => {
  const state = await mockProducts(page)
  page.once('dialog', (dialog) => dialog.accept())
  await page.goto('/dashboard/products')
  await page.getByRole('button', { name: 'Eliminar' }).first().click()
  await expect.poll(() => state.deleted).toBe(1)
  await expect(page.getByRole('status')).toHaveText('Producto eliminado.')
})

test('renders API errors and product not found states', async ({ page }) => {
  await mockProducts(page, { listError: true, detail404: true })
  await page.goto('/dashboard/products')
  await expect(page.getByRole('alert')).toContainText('No fue posible consultar productos.')
  await page.goto('/dashboard/products/1/edit')
  await expect(page.getByText('Producto no encontrado', { exact: true })).toBeVisible()
})

test('renders a product permission error without a blank screen', async ({ page }) => {
  await mockProducts(page, { listStatus: 403, listMessage: 'No tienes permiso para consultar productos.' })
  await page.goto('/dashboard/products')
  await expect(page.getByRole('alert')).toContainText('No tienes permiso para consultar productos.')
  await expect(page.getByRole('heading', { name: 'Productos' })).toBeVisible()
})

test('redirects to login when the product session expires', async ({ page }) => {
  await mockProducts(page, { listStatus: 401, listMessage: 'Unauthenticated.' })
  await page.goto('/dashboard/products')
  await expect(page).toHaveURL(/\/login\?redirect=\/dashboard\/products$/)
})

test('uses product cards without horizontal overflow on mobile', async ({ page }) => {
  await mockProducts(page)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/dashboard/products')
  await expect(page.locator('.product-card').first()).toBeVisible()
  await expect(page.locator('.product-card').first()).toContainText('Stock: 8')
  await expect(page.locator('.product-table')).toBeHidden()
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
})

test('keeps the desktop table contained near the responsive breakpoint', async ({ page }) => {
  await mockProducts(page)
  await page.setViewportSize({ width: 800, height: 900 })
  await page.goto('/dashboard/products')
  await expect(page.locator('.product-table')).toBeVisible()
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
})

test('blocks creation when the confirmed plan limit is reached', async ({ page }) => {
  await page.unroute('**/api/v1/my-subscription')
  await mockDashboardShell(page, { maxProducts: 1 })
  await mockProducts(page)
  await page.goto('/dashboard/products/create')
  await expect(page.getByRole('alert')).toContainText('Alcanzaste el límite de 1 productos')
  await expect(page.getByRole('button', { name: 'Crear producto' })).not.toBeVisible()
})

test('rejects non-store user access through the existing role guard', async ({ page }) => {
  const deliver = { ...storeUser, type: '3', roles: ['deliver'], permissions: [] }
  await page.context().clearCookies()
  await page.addInitScript((user) => {
    localStorage.setItem('token', 'deliver-token')
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.setItem('onboarding_done', 'true')
  }, deliver)
  await page.unroute('**/api/v1/user')
  await page.route('**/api/v1/user', (route) => route.fulfill({ json: { data: deliver } }))
  await page.goto('/dashboard/products')
  await expect(page).toHaveURL(/\/delivery$/)
  await expect(page.getByRole('heading', { name: 'Pedidos disponibles' })).toBeVisible()
})

test('rejects product creation without products.create permission', async ({ page }) => {
  const readOnlyUser = { ...storeUser, permissions: ['products.read'] }
  await mockProducts(page)
  await page.addInitScript((user) => {
    localStorage.setItem('token', 'read-only-token')
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.setItem('onboarding_done', 'true')
  }, readOnlyUser)
  await page.unroute('**/api/v1/user')
  await page.route('**/api/v1/user', (route) => route.fulfill({ json: { data: readOnlyUser } }))
  await page.goto('/dashboard/products/create')
  await expect(page).toHaveURL(/\/dashboard\/products$/)
  await expect(page.getByRole('heading', { name: 'Productos' })).toBeVisible()
})

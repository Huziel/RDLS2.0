import { expect, test } from '@playwright/test'

const SERIAL = 'E2E-STORE'
const ORDER_REF = 'ORD-777'

const PRODUCT = {
  id: 7,
  nombre: 'Nada de Ella',
  precio: '150.00',
  descripcion: 'Clásico de dona glaseada',
  categoria: 'Postres',
  imagen: null,
  stock: 10,
}

const PRODUCT_DETAIL = {
  ...PRODUCT,
  aditivos: [
    { id: 31, nombre: 'Adicional', precio: 10, activo: true },
    { id: 32, nombre: 'Extra', precio: 5, activo: true },
  ],
}

const CART_ITEM = {
  id: 4,
  product_id: PRODUCT.id,
  product_name: PRODUCT.nombre,
  price: Number(PRODUCT.precio),
  quantity: 1,
  addons: [],
  discount: 0,
  product_image: null,
}

const THEME = {
  data: {
    extra: {
      nombre_tienda: 'Tienda E2E',
      nombre_banco1: 'Banco de Prueba',
      nombre_propietario1: 'María López',
      transferencia1: '0000 1111 2222 3333',
    },
    colors: { primary: '#16a34a', dark: '#0f172a', light: '#f8fafc' },
    has_password: false,
    features: { shipping: true },
    shipping_costs: { basic: '30' },
  },
}

const ORDER_DETAIL = {
  data: {
    order: ORDER_REF,
    cliente: 'Ana',
    telefono: '555 0102 3344',
    fecha: '22/09/2026 12:00',
    envio: 30,
    total: 150,
    items: [
      { name: PRODUCT.nombre, qty: 1, price: Number(PRODUCT.precio), addons: [] },
    ],
  },
}

function fulfillJson(route, body, status = 200) {
  return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

async function stubStoreAndTheme(page) {
  await page.route(`**/api/v1/public/stores/${SERIAL}`, (route) => fulfillJson(route, { data: { id: 1, serial: SERIAL, extra: { nombre_tienda: 'Tienda E2E' } } }))
  await page.route(`**/api/v1/public/stores/${SERIAL}/theme`, (route) => fulfillJson(route, THEME))
}

async function stubCart(page, items = []) {
  await page.route(`**/api/v1/stores/${SERIAL}/cart`, async (route) => {
    if (route.request().method() === 'POST') {
      return fulfillJson(route, { data: { items } })
    }
    return fulfillJson(route, { data: { items, count: items.length, total: items.reduce((sum, i) => sum + i.price, 0) } })
  })
}

test.beforeEach(async ({ page }) => {
  await page.route('https://api.qrserver.com/**', (route) => route.abort())
  await page.route('https://fonts.googleapis.com/**', (route) => route.abort())
  await page.route('https://fonts.gstatic.com/**', (route) => route.abort())
  await stubStoreAndTheme(page)
})

test('home renders the catalog and the cart badge updates after adding', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  await page.route(`**/api/v1/public/stores/${SERIAL}/products*`, (route) => fulfillJson(route, { data: [PRODUCT], meta: { total: 1, per_page: 200 } }))

  await page.goto(`/store/${SERIAL}`)
  await expect(page.getByRole('heading', { name: PRODUCT.nombre })).toBeVisible()
  await expect(page.getByText('Sin imagen', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: `Ver ${PRODUCT.nombre}` })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Agregar' })).toBeVisible()

  await page.getByRole('button', { name: 'Agregar' }).click()
  await expect(page.locator('.ps-toast')).toHaveText(/agregado al carrito/)
  await expect(page.locator('.ps-cart-badge')).toHaveText('1')
})

test('empty cart shows its empty state and a back to catalog link', async ({ page }) => {
  await stubCart(page, [])
  await page.goto(`/store/${SERIAL}/cart`)
  await expect(page.getByText('Tu carrito está vacío', { exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Ver productos' })).toBeVisible()
})

test('product detail totals include selected addons and adds to the cart', async ({ page }) => {
  await stubCart(page, [])
  await page.route(`**/api/v1/public/products/${PRODUCT.id}`, (route) => fulfillJson(route, { data: PRODUCT_DETAIL }))

  await page.goto(`/store/${SERIAL}/product/${PRODUCT.id}`)
  await expect(page.getByRole('heading', { name: PRODUCT.nombre })).toBeVisible()
  await expect(page.getByText('Extras')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Agregar · $150.00' })).toBeVisible()

  await page.getByLabel('Adicional').check()
  await expect(page.getByRole('button', { name: 'Agregar · $160.00' })).toBeVisible()

  await page.getByRole('button', { name: 'Agregar · $160.00' }).click()
  await expect(page.locator('.ps-alert-success')).toHaveText(/agregado\(s\) al carrito/)
})

test('checkout places the order and the thanks page shows the folio', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  await page.route(`**/api/v1/stores/${SERIAL}/checkout`, (route) => {
    expect(route.request().method()).toBe('POST')
    return fulfillJson(route, { data: { order_id: ORDER_REF }, message: 'Pedido creado.' })
  })
  await page.route(`**/api/v1/public/orders/${ORDER_REF}`, (route) => fulfillJson(route, ORDER_DETAIL))

  await page.goto(`/store/${SERIAL}/cart`)
  await expect(page.getByText('Nada de Ella')).toBeVisible()
  await page.getByRole('button', { name: 'Ir a pagar' }).click()
  await expect(page).toHaveURL(/\/checkout$/)

  await page.locator('#co-nombre').fill('Ana')
  await page.locator('#co-telefono').fill('555 0102 3344')
  await page.locator('#co-direccion').fill('Calle Falsa 123')
  await page.locator('#co-ciudad').fill('Guadalajara')
  await page.locator('#co-cp').fill('44100')
  await page.getByRole('button', { name: 'Continuar a pago' }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${SERIAL}/thanks\\?order=${ORDER_REF}`))
  await expect(page.getByRole('heading', { name: '¡Gracias, Ana!' })).toBeVisible()
  await expect(page.locator('.ps-order-lines dd').filter({ hasText: ORDER_REF })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Detalle del pedido' })).toBeVisible()
})

test('thanks page generates a real MercadoPago pay link', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  await page.route(`**/api/v1/public/orders/${ORDER_REF}`, (route) => fulfillJson(route, ORDER_DETAIL))
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}/pay`, (route) =>
    fulfillJson(route, { data: { init_point: 'https://sandbox.mercadopago.com/e2e-checkout' } }))

  await page.goto(`/store/${SERIAL}/thanks?order=${ORDER_REF}`)
  await page.getByRole('button', { name: 'Generar link de pago' }).click()
  const link = page.getByRole('link', { name: 'Ir a pagar' })
  await expect(link).toBeVisible()
  await expect(link).toHaveAttribute('href', 'https://sandbox.mercadopago.com/e2e-checkout')
})

test('thanks page cancels an unpaid order and reports restored stock', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  let cancelledOrder = false
  await page.route(`**/api/v1/public/orders/${ORDER_REF}`, (route) =>
    fulfillJson(route, cancelledOrder
      ? {
          data: {
            ...ORDER_DETAIL.data,
            order_state: 'cancelled',
            estado: 'cancelada',
            cancelled_at: '22/09/2026 12:10',
          },
        }
      : ORDER_DETAIL))
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}/cancel`, (route) => {
    cancelledOrder = true
    return fulfillJson(route, { data: { status: 'cancelled', idempotent: false, restocked: true, reversed: false }, message: 'Orden cancelada.' })
  })

  await page.goto(`/store/${SERIAL}/thanks?order=${ORDER_REF}`)
  await expect(page.getByRole('button', { name: 'Cancelar pedido' })).toBeVisible()
  page.on('dialog', (dialog) => dialog.accept())
  await page.getByRole('button', { name: 'Cancelar pedido' }).click()
  await expect(page.locator('.ps-alert-success')).toHaveText(/Pedido cancelado/)
  await expect(page.getByText('Cancelada', { exact: true })).toBeVisible()
})

test('home never overflows on a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await stubCart(page)
  await page.route(`**/api/v1/public/stores/${SERIAL}/products*`, (route) => fulfillJson(route, { data: [PRODUCT], meta: { total: 1, per_page: 200 } }))

  await page.goto(`/store/${SERIAL}`)
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
})
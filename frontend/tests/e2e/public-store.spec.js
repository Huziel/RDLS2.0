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
    features: { pickup: true, shipping: true },
    shipping_costs: { base: '30', medio: '10', largo: '20' },
    payment_methods: { cash: true, bank_transfer: true, mercado_pago: true, cash_on_delivery: true },
  },
}

function orderTicket(overrides = {}) {
  return {
    data: {
      order: ORDER_REF,
      cliente: 'Ana',
      fecha: '22/09/2026 12:00',
      envio: 30,
      total: 150,
      status: 'pending',
      payment_status: 'pending',
      payment_method: 'bank_transfer',
      can_pay: false,
      can_cancel: true,
      items: [
        { name: PRODUCT.nombre, qty: 1, price: Number(PRODUCT.precio), addons: [] },
      ],
      ...overrides,
    },
  }
}

const ORDER_DETAIL = orderTicket()
const ORDER_DETAIL_MP = orderTicket({
  payment_method: 'mercado_pago',
  can_pay: true,
})

function fulfillJson(route, body, status = 200) {
  return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

async function stubStoreAndTheme(page) {
  await page.route(`**/api/v1/public/stores/${SERIAL}`, (route) => fulfillJson(route, { data: { id: 1, serial: SERIAL, name: 'Tienda E2E' } }))
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
  await page.route('**/api/v1/public/site-settings', (route) => fulfillJson(route, { data: {} }))
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
  await page.route(`**/api/v1/public/stores/${SERIAL}/products/${PRODUCT.id}`, (route) => fulfillJson(route, { data: PRODUCT_DETAIL }))

  await page.goto(`/store/${SERIAL}/product/${PRODUCT.id}`)
  await expect(page.getByRole('heading', { name: PRODUCT.nombre })).toBeVisible()
  await expect(page.getByText('Extras')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Agregar · $150.00' })).toBeVisible()

  await page.getByLabel('Adicional').check()
  await expect(page.getByRole('button', { name: 'Agregar · $160.00' })).toBeVisible()

  await page.getByRole('button', { name: 'Agregar · $160.00' }).click()
  await expect(page.locator('.ps-alert-success')).toHaveText(/agregado\(s\) al carrito/)
})

test('cash on delivery is only offered with local shipping', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  await page.goto(`/store/${SERIAL}/checkout`)

  await expect(page.getByText('Forma de pago')).toBeVisible()
  await expect(page.getByLabel('Pago contra entrega')).toBeVisible()

  await page.getByLabel('Retiro en tienda').check()
  await expect(page.getByLabel('Pago contra entrega')).toHaveCount(0)
  await expect(page.getByLabel('Efectivo')).toBeChecked()

  await page.getByLabel('Envío local').check()
  await expect(page.getByLabel('Pago contra entrega')).toBeVisible()
})

test('checkout fails closed when payment readiness cannot be loaded', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  let checkoutPosts = 0
  await page.route(`**/api/v1/public/stores/${SERIAL}/theme`, (route) =>
    fulfillJson(route, { message: 'No disponible' }, 500))
  await page.route(`**/api/v1/stores/${SERIAL}/checkout`, (route) => {
    checkoutPosts += 1
    return fulfillJson(route, { message: 'No debe llamarse' }, 500)
  })

  await page.goto(`/store/${SERIAL}/checkout`)
  await expect(page.getByRole('alert')).toContainText('No disponible')
  await expect(page.getByRole('button', { name: 'Continuar a pago' })).toBeDisabled()
  await page.getByRole('button', { name: 'Continuar a pago' }).click({ force: true })
  expect(checkoutPosts).toBe(0)
})

test('checkout sends the payment method and an idempotency key then thanks shows the folio', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  await page.route(`**/api/v1/stores/${SERIAL}/checkout`, (route) => {
    expect(route.request().method()).toBe('POST')
    const body = route.request().postDataJSON()
    expect(body.payment_method).toBe('bank_transfer')
    expect(route.request().headers()['idempotency-key']).toMatch(/^ck-/)
    return fulfillJson(route, { data: { order_id: ORDER_REF }, message: 'Pedido creado.' })
  })
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}`, (route) => fulfillJson(route, ORDER_DETAIL))

  await page.goto(`/store/${SERIAL}/cart`)
  await expect(page.getByText('Nada de Ella')).toBeVisible()
  await page.getByRole('button', { name: 'Ir a pagar' }).click()
  await expect(page).toHaveURL(/\/checkout$/)
  await expect(page.locator('.ps-checkout-summary')).toContainText('Envío$30.00')

  await page.locator('#co-nombre').fill('Ana')
  await page.locator('#co-telefono').fill('555 0102 3344')
  await page.locator('#co-direccion').fill('Calle Falsa 123')
  await page.locator('#co-ciudad').fill('Guadalajara')
  await page.locator('#co-cp').fill('44100')
  await page.getByLabel('Transferencia bancaria').check()
  await page.getByRole('button', { name: 'Continuar a pago' }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${SERIAL}/thanks\\?order=${ORDER_REF}`))
  await expect(page.getByRole('heading', { name: '¡Gracias, Ana!' })).toBeVisible()
  await expect(page.locator('.ps-order-lines dd').filter({ hasText: ORDER_REF })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Detalle del pedido' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Pago por transferencia' })).toBeVisible()
  await expect(page.locator('.ps-qr')).toHaveAttribute('src', /^data:image\/png;base64,/)
})

test('thanks page generates a real MercadoPago pay link with a deterministic key', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}`, (route) => fulfillJson(route, ORDER_DETAIL_MP))
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}/pay`, (route) => {
    expect(route.request().headers()['idempotency-key']).toMatch(/^pay-/)
    return fulfillJson(route, { data: { init_point: 'https://sandbox.mercadopago.com/e2e-checkout' } })
  })

  await page.goto(`/store/${SERIAL}/thanks?order=${ORDER_REF}`)
  await page.getByRole('button', { name: 'Generar link de pago' }).click()
  const link = page.getByRole('link', { name: 'Ir a pagar' })
  await expect(link).toBeVisible()
  await expect(link).toHaveAttribute('href', 'https://sandbox.mercadopago.com/e2e-checkout')
})

test('thanks page cancels an unpaid order with a deterministic key and reports restored stock', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  let cancelledOrder = false
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}`, (route) =>
    fulfillJson(route, cancelledOrder
      ? orderTicket({ status: 'cancelled', can_cancel: false })
      : ORDER_DETAIL))
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}/cancel`, (route) => {
    expect(route.request().headers()['idempotency-key']).toMatch(/^cancel-/)
    cancelledOrder = true
    return fulfillJson(route, { data: { status: 'cancelled', idempotent: false, restocked: true, reversed: false }, message: 'Orden cancelada. Se repuso el inventario.' })
  })

  await page.goto(`/store/${SERIAL}/thanks?order=${ORDER_REF}`)
  await expect(page.getByRole('button', { name: 'Cancelar pedido' })).toBeVisible()
  page.on('dialog', (dialog) => dialog.accept())
  await page.getByRole('button', { name: 'Cancelar pedido' }).click()
  await expect(page.locator('.ps-alert-success')).toHaveText(/Se repuso el inventario/)
  await expect(page.getByText('Cancelada', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Cancelar pedido' })).toHaveCount(0)
})

test('thanks page never claims restock when physical return is pending', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  let cancelledOrder = false
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}`, (route) =>
    fulfillJson(route, cancelledOrder
      ? orderTicket({ status: 'cancelled', can_cancel: false })
      : ORDER_DETAIL))
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}/cancel`, (route) => {
    cancelledOrder = true
    return fulfillJson(route, {
      data: { status: 'cancelled', restocked: false, return_pending: true },
      message: 'Orden cancelada. La entrega ya habia salido; se requiere retorno fisico.',
    })
  })

  await page.goto(`/store/${SERIAL}/thanks?order=${ORDER_REF}`)
  page.on('dialog', (dialog) => dialog.accept())
  await page.getByRole('button', { name: 'Cancelar pedido' }).click()
  await expect(page.locator('.ps-alert-success')).toContainText('retorno fisico')
  await expect(page.locator('.ps-alert-success')).not.toContainText('Stock restaurado')
  await expect(page.locator('.ps-alert-success')).not.toContainText('repuso el inventario')
})

test('expired catalog capability relocks without clearing the owner session', async ({ page }) => {
  await page.addInitScript((serial) => {
    localStorage.setItem('token', 'owner-session-token')
    sessionStorage.setItem(`ps-cap:${serial.toLowerCase()}`, 'expired-capability')
  }, SERIAL)
  await page.route(`**/api/v1/public/stores/${SERIAL}/theme`, (route) =>
    fulfillJson(route, { data: { ...THEME.data, has_password: true } }))
  await page.route('**/api/v1/user', (route) =>
    fulfillJson(route, { data: { id: 99, name: 'owner@example.test', type: '1' } }))
  await page.route(`**/api/v1/public/stores/${SERIAL}/products*`, (route) =>
    fulfillJson(route, { message: 'Capability expirada.' }, 401))
  await page.route(`**/api/v1/stores/${SERIAL}/cart`, (route) =>
    fulfillJson(route, { message: 'Capability expirada.' }, 401))

  await page.goto(`/store/${SERIAL}`)
  await expect(page.getByRole('heading', { name: 'Catálogo protegido' })).toBeVisible()
  const storage = await page.evaluate((serial) => ({
    ownerToken: localStorage.getItem('token'),
    capability: sessionStorage.getItem(`ps-cap:${serial.toLowerCase()}`),
  }), SERIAL)
  expect(storage.ownerToken).toBe('owner-session-token')
  expect(storage.capability).toBeNull()
})

test('valid catalog capability survives reload without showing the password gate', async ({ page }) => {
  await page.addInitScript((serial) => {
    sessionStorage.setItem(`ps-cap:${serial.toLowerCase()}`, 'valid-capability')
  }, SERIAL)
  await stubCart(page, [CART_ITEM])
  await page.route(`**/api/v1/public/stores/${SERIAL}/theme`, (route) =>
    fulfillJson(route, { data: { ...THEME.data, has_password: true, catalog_locked: false } }))
  await page.route(`**/api/v1/public/stores/${SERIAL}/products*`, (route) => {
    expect(route.request().headers()['x-store-capability']).toBe('valid-capability')
    return fulfillJson(route, { data: [PRODUCT], meta: { total: 1, per_page: 200 } })
  })

  await page.goto(`/store/${SERIAL}`)
  await expect(page.getByRole('heading', { name: PRODUCT.nombre })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Catálogo protegido' })).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('heading', { name: PRODUCT.nombre })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Catálogo protegido' })).toHaveCount(0)
})

test('payment exception blocks cancellation and shows the alert', async ({ page }) => {
  await stubCart(page, [CART_ITEM])
  await page.route(`**/api/v1/stores/${SERIAL}/orders/${ORDER_REF}`, (route) =>
    fulfillJson(route, orderTicket({ status: 'pending', payment_status: 'payment_exception', can_pay: false, can_cancel: false })))

  await page.goto(`/store/${SERIAL}/thanks?order=${ORDER_REF}`)
  await expect(page.locator('.ps-alert-error')).toHaveText(/excepción de cobro/i)
  await expect(page.getByRole('button', { name: 'Cancelar pedido' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Generar link de pago' })).toHaveCount(0)
})

test('home never overflows on a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await stubCart(page)
  await page.route(`**/api/v1/public/stores/${SERIAL}/products*`, (route) => fulfillJson(route, { data: [PRODUCT], meta: { total: 1, per_page: 200 } }))

  await page.goto(`/store/${SERIAL}`)
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
})

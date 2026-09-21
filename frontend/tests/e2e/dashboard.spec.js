import { expect, test } from '@playwright/test'

const user = {
  id: 1,
  name: 'tienda@example.com',
  type: '1',
  roles: ['store-owner'],
  permissions: ['dashboard.view', 'pos.history'],
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript((sessionUser) => {
    localStorage.setItem('token', 'test-token')
    localStorage.setItem('user', JSON.stringify(sessionUser))
    localStorage.setItem('onboarding_done', 'true')
  }, user)

  await page.route('**/api/v1/public/site-settings', (route) => route.fulfill({ json: { data: { site_name: 'Ruta de la Seda' } } }))
  await page.route('**/api/v1/user', (route) => route.fulfill({ json: { data: user } }))
  await page.route('**/api/v1/store', (route) => route.fulfill({ json: { data: { serial: 'DEMO-01', logo: null } } }))
  await page.route('**/api/v1/store/extra', (route) => route.fulfill({ json: { data: { nombre_tienda: 'Tienda Demo' } } }))
  await page.route('**/api/v1/my-subscription', (route) => route.fulfill({ json: { data: { plan_name: 'Profesional' } } }))
  await page.route('**/api/v1/dashboard/stats', (route) => route.fulfill({
    json: {
      data: {
        products: 8,
        active_products: 6,
        low_stock: 2,
        online_orders: 4,
        online_revenue: 900,
        pos_orders: 3,
        pos_revenue: 350.5,
        today_revenue: 1250.5,
        today_appointments: 2,
        recent_online: [],
        recent_pos: [],
      },
    },
  }))
})

test('renders real dashboard contracts and subscription name', async ({ page }) => {
  await page.goto('/dashboard')
  await expect(page.getByRole('heading', { name: 'Panel de control' })).toBeVisible()
  const menuButton = page.getByRole('button', { name: 'Abrir menú' })
  if (await menuButton.isVisible()) await menuButton.click()
  await expect(page.getByText('Tienda Demo')).toBeVisible()
  await expect(page.getByText('Profesional')).toBeVisible()
  await expect(page.getByText('6', { exact: true })).toBeVisible()
  await expect(page.getByText('Stock bajo')).toBeVisible()
  await expect(page.getByText('2', { exact: true })).toBeVisible()
})

test('dashboard fits a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/dashboard')
  await expect(page.getByRole('button', { name: 'Abrir menú' })).toBeVisible()
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
})

test('hides and rejects POS access without pos.use', async ({ page }) => {
  await page.goto('/dashboard')
  const menuButton = page.getByRole('button', { name: 'Abrir menú' })
  if (await menuButton.isVisible()) await menuButton.click()
  await expect(page.getByRole('link', { name: 'Punto de venta' })).toHaveCount(0)

  await page.goto('/dashboard/pos')
  await expect(page).toHaveURL(/\/dashboard$/)
})

test('allows POS use but hides history without pos.history', async ({ page }) => {
  const operator = { ...user, permissions: ['dashboard.view', 'pos.use'] }
  await page.addInitScript((sessionUser) => {
    localStorage.setItem('token', 'pos-operator-token')
    localStorage.setItem('user', JSON.stringify(sessionUser))
    localStorage.setItem('onboarding_done', 'true')
  }, operator)
  await page.unroute('**/api/v1/user')
  await page.route('**/api/v1/user', (route) => route.fulfill({ json: { data: operator } }))

  await page.goto('/dashboard')
  const menuButton = page.getByRole('button', { name: 'Abrir menú' })
  if (await menuButton.isVisible()) await menuButton.click()
  await expect(page.getByRole('link', { name: 'Punto de venta' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Actividad POS' })).toHaveCount(0)

  await page.goto('/dashboard/pos/history')
  await expect(page).toHaveURL(/\/dashboard$/)
})

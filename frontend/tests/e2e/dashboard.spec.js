import { expect, test } from '@playwright/test'

const user = {
  id: 1,
  name: 'tienda@example.com',
  type: '1',
  roles: ['store-owner'],
  permissions: ['dashboard.view'],
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
})

test('dashboard fits a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/dashboard')
  await expect(page.getByRole('button', { name: 'Abrir menú' })).toBeVisible()
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
})

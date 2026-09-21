import { expect, test } from '@playwright/test'

test.beforeEach(async ({ page }) => {
  await page.route('**/api/v1/public/site-settings', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: { site_name: 'Ruta de la Seda', login_colors: {} } }),
  }))
})

test('renders the reconstructed login form', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: 'Ruta de la Seda' })).toBeVisible()
  await expect(page.getByLabel('Correo electrónico o teléfono')).toBeVisible()
  await expect(page.getByLabel('Contraseña')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Iniciar sesión' })).toBeVisible()
})

test('does not overflow on a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/login')
  const sizes = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  const card = await page.locator('.auth-card').boundingBox()
  expect(sizes.width).toBeLessThanOrEqual(sizes.viewport)
  expect(card.x).toBeGreaterThanOrEqual(0)
  expect(card.x + card.width).toBeLessThanOrEqual(sizes.viewport)
})

test('shows an API authentication error', async ({ page }) => {
  await page.route('**/api/v1/auth/login', (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ message: 'Credenciales incorrectas.' }),
  }))

  await page.goto('/login')
  await page.getByLabel('Correo electrónico o teléfono').fill('nadie@example.com')
  await page.getByLabel('Contraseña').fill('incorrecta')
  await page.getByRole('button', { name: 'Iniciar sesión' }).click()
  await expect(page.getByRole('alert')).toHaveText('Credenciales incorrectas.')
})

test('keeps a non-remembered session in session storage', async ({ page }) => {
  await page.route('**/api/v1/auth/login', (route) => route.fulfill({
    json: {
      data: {
        token: 'session-token',
        user: { id: 3, name: 'repartidor@example.com', type: '3', roles: ['deliver'], permissions: [] },
      },
    },
  }))

  await page.goto('/login')
  await page.getByLabel('Correo electrónico o teléfono').fill('repartidor@example.com')
  await page.getByLabel('Contraseña').fill('correcta')
  await page.getByRole('button', { name: 'Iniciar sesión' }).click()

  await expect(page).toHaveURL(/\/delivery$/)
  const storage = await page.evaluate(() => ({
    sessionToken: sessionStorage.getItem('token'),
    localToken: localStorage.getItem('token'),
  }))
  expect(storage).toEqual({ sessionToken: 'session-token', localToken: null })
})

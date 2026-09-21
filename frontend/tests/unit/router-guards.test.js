import { describe, expect, it, vi } from 'vitest'
import { createAuthGuard, homeForUserType } from '../../src/router/guards.js'

function auth(overrides = {}) {
  return {
    bootstrap: vi.fn(),
    isAuthenticated: false,
    userType: '',
    hasRole: () => false,
    ...overrides,
  }
}

const storage = { getItem: () => 'true' }

describe('route guards', () => {
  it('maps each user type to its home', () => {
    expect(homeForUserType('1')).toBe('/dashboard')
    expect(homeForUserType('3')).toBe('/delivery')
    expect(homeForUserType('4')).toBe('/customer')
  })

  it('sends anonymous users to login', async () => {
    const guard = createAuthGuard(auth(), storage)
    await expect(guard({ meta: { requiresAuth: true }, fullPath: '/dashboard', path: '/dashboard' })).resolves.toEqual({
      path: '/login',
      query: { redirect: '/dashboard' },
    })
  })

  it('blocks admin routes without the required role', async () => {
    const guard = createAuthGuard(auth({ isAuthenticated: true, userType: '1' }), storage)
    await expect(guard({ meta: { roles: ['super-admin'] }, path: '/dashboard/admin-users' })).resolves.toBe('/dashboard')
  })

  it('allows a user with the required role', async () => {
    const guard = createAuthGuard(auth({
      isAuthenticated: true,
      userType: '1',
      hasRole: (role) => role === 'super-admin',
    }), storage)
    await expect(guard({ meta: { roles: ['super-admin'] }, path: '/dashboard/admin-users' })).resolves.toBe(true)
  })

  it('redirects authenticated guests to their area', async () => {
    const guard = createAuthGuard(auth({ isAuthenticated: true, userType: '3' }), storage)
    await expect(guard({ meta: { guest: true }, path: '/login' })).resolves.toBe('/delivery')
  })

  it('enforces confirmed product permissions', async () => {
    const guard = createAuthGuard(auth({
      isAuthenticated: true,
      userType: '1',
      can: (permission) => permission === 'products.read',
    }), storage)
    await expect(guard({ meta: { permission: 'products.read' }, path: '/dashboard/products' })).resolves.toBe(true)
    await expect(guard({ meta: { permission: 'products.create' }, path: '/dashboard/products/create' })).resolves.toBe('/dashboard')
  })

  it('requires every permission declared by a product route', async () => {
    const guard = createAuthGuard(auth({
      isAuthenticated: true,
      userType: '1',
      can: (permission) => permission === 'products.update',
    }), storage)
    await expect(guard({ meta: { permissions: ['products.read', 'products.update'] }, path: '/dashboard/products/1/edit' })).resolves.toBe('/dashboard')
  })

  it('enforces the existing POS permissions independently', async () => {
    const guard = createAuthGuard(auth({
      isAuthenticated: true,
      userType: '1',
      can: (permission) => permission === 'pos.history',
    }), storage)
    await expect(guard({ meta: { permission: 'pos.use' }, path: '/dashboard/pos' })).resolves.toBe('/dashboard')
    await expect(guard({ meta: { permission: 'pos.history' }, path: '/dashboard/pos/history' })).resolves.toBe(true)
  })

  it('redirects users without dashboard access to an allowed module', async () => {
    const guard = createAuthGuard(auth({
      isAuthenticated: true,
      userType: '1',
      can: (permission) => permission === 'products.read',
    }), storage)
    await expect(guard({ meta: { permission: 'dashboard.view' }, path: '/dashboard' })).resolves.toBe('/dashboard/products')
  })
})

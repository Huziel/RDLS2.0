import { beforeEach, describe, expect, it, vi } from 'vitest'

function memoryStorage() {
  const data = new Map()
  return {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => void data.set(key, String(value)),
    removeItem: (key) => void data.delete(key),
    clear: () => void data.clear(),
    length: 0,
    key: () => null,
  }
}

Object.defineProperty(globalThis, 'localStorage', { value: memoryStorage(), configurable: true })
Object.defineProperty(globalThis, 'sessionStorage', { value: memoryStorage(), configurable: true })
import {
  cartCount,
  cartSubtotal,
  cartToken,
  checkoutIdempotencyKey,
  clearStoreCapability,
  ensureCartToken,
  lineTotal,
  money,
  orderIdempotencyKey,
  resetCartToken,
  selectableStock,
  setStoreCapability,
  storeCapability,
} from '../../src/utils/public-store.js'

vi.mock('../../src/api/client.js', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '../../src/api/client.js'
import { publicStoreApi } from '../../src/api/public-store.js'

describe('public store utilities', () => {
  it('formats amounts as local currency strings', () => {
    expect(money(12.5)).toBe('$12.50')
    expect(money('7')).toBe('$7.00')
    expect(money(undefined)).toBe('$0.00')
    expect(money(NaN)).toBe('$0.00')
  })

  it('aggregates cart quantities, line totals and subtotals', () => {
    const items = [
      { price: 100, quantity: 2 },
      { price: 25.5, quantity: 1 },
      { price: 0, quantity: 3 },
    ]
    expect(lineTotal(items[0])).toBe(100)
    expect(cartSubtotal(items)).toBe(125.5)
    expect(cartCount(items)).toBe(6)
  })

  it('keeps stock non-negative and numeric', () => {
    expect(selectableStock(5)).toBe(5)
    expect(selectableStock('-2')).toBe(0)
    expect(selectableStock(undefined)).toBe(0)
  })

  it('creates a stable cart token once and clears it after checkout', () => {
    localStorage.clear()
    const first = ensureCartToken()
    expect(first).toBeTruthy()
    expect(ensureCartToken()).toBe(first)
    expect(cartToken()).toBe(first)
    resetCartToken()
    expect(cartToken()).toBe('')
  })

  it('stores the catalog capability per store and clears it', () => {
    sessionStorage.clear()
    expect(storeCapability('serieA')).toBe('')
    setStoreCapability('serieA', 'cap-abc')
    expect(storeCapability('serieA')).toBe('cap-abc')
    expect(storeCapability('SERIEA')).toBe('cap-abc')
    expect(storeCapability('serieB')).toBe('')
    clearStoreCapability('SERIEA')
    expect(storeCapability('serieA')).toBe('')
  })

  it('derives a deterministic checkout key that changes with content', () => {
    const token = ensureCartToken()
    const payload = { tipo_envio: 'shipping', payment_method: 'cash', costo_envio: 30, direccion: 'Calle 1', ciudad: 'GDL', codigo_postal: '44100' }
    const items = [{ id: 7, quantity: 2, addons: [{ id: 31 }, { id: 32 }] }]

    const key = checkoutIdempotencyKey('serieA', payload, items, token)
    expect(key).toBe(checkoutIdempotencyKey('serieA', payload, items, token))
    expect(checkoutIdempotencyKey('serieA', payload, items, token)).not.toBe(
      checkoutIdempotencyKey('serieA', { ...payload, payment_method: 'bank_transfer' }, items, token),
    )
    expect(checkoutIdempotencyKey('serieA', payload, items, token)).not.toBe(
      checkoutIdempotencyKey('serieA', payload, [{ id: 7, quantity: 1 }], token),
    )
    expect(checkoutIdempotencyKey('serieA', payload, items, token)).not.toBe(
      checkoutIdempotencyKey('serieA', { ...payload, nombre: 'Ana' }, items, token),
    )
    expect(checkoutIdempotencyKey('serieA', payload, items, token)).not.toBe(
      checkoutIdempotencyKey('serieA', { ...payload, telefono: '5551112222' }, items, token),
    )
    expect(key.length).toBeLessThanOrEqual(100)
  })

  it('derives stable pay and cancel keys per order', () => {
    expect(orderIdempotencyKey('pay', 'serieA', 'ORD/000001')).toBe(orderIdempotencyKey('pay', 'serieA', 'ORD/000001'))
    expect(orderIdempotencyKey('pay', 'serieA', 'ORD/000001')).not.toBe(orderIdempotencyKey('cancel', 'serieA', 'ORD/000001'))
    expect(orderIdempotencyKey('pay', 'serieA', 'ORD/000001')).not.toBe(orderIdempotencyKey('pay', 'serieB', 'ORD/000001'))
  })
})

describe('public store API service', () => {
  beforeEach(() => vi.clearAllMocks())

  it('attaches the cart token header to every store request', () => {
    const token = ensureCartToken()
    publicStoreApi.cart('serieA')
    expect(api.get).toHaveBeenCalledWith('/stores/serieA/cart', { headers: { 'X-Cart-Token': token } })

    publicStoreApi.removeCartItem('serieA', 7)
    expect(api.delete).toHaveBeenCalledWith('/stores/serieA/cart/7', { headers: { 'X-Cart-Token': token } })
  })

  it('attaches the capability header only when it exists for that store', () => {
    sessionStorage.clear()
    const token = ensureCartToken()
    setStoreCapability('serieA', 'cap-xyz')

    publicStoreApi.products('serieA', { category: 'Frios', per_page: 200 })
    expect(api.get).toHaveBeenCalledWith('/public/stores/serieA/products', {
      params: { category: 'Frios', per_page: 200 },
      headers: { 'X-Cart-Token': token, 'X-Store-Capability': 'cap-xyz' },
    })

    publicStoreApi.products('serieB', { per_page: 200 })
    expect(api.get).toHaveBeenNthCalledWith(2, '/public/stores/serieB/products', {
      params: { per_page: 200 },
      headers: { 'X-Cart-Token': token },
    })
  })

  it('routes catalog, product, addons and theme reads to tenant public endpoints', () => {
    publicStoreApi.store('serieA')
    publicStoreApi.products('serieA', { category: 'Frios', per_page: 200 })
    publicStoreApi.product('serieA', 8)
    publicStoreApi.productAddons('serieA', 8)

    expect(api.get).toHaveBeenNthCalledWith(1, '/public/stores/serieA', expect.anything())
    expect(api.get).toHaveBeenNthCalledWith(2, '/public/stores/serieA/products', {
      params: { category: 'Frios', per_page: 200 },
      headers: expect.anything(),
    })
    expect(api.get).toHaveBeenNthCalledWith(3, '/public/stores/serieA/products/8', expect.anything())
    expect(api.get).toHaveBeenNthCalledWith(4, '/public/stores/serieA/products/8/addons', expect.anything())
  })

  it('stores the capability returned by unlock', async () => {
    sessionStorage.clear()
    api.post.mockResolvedValueOnce({ data: { capability: 'cap-abc' } })
    const res = await publicStoreApi.unlock('serieA', 'secreto')
    expect(api.post).toHaveBeenCalledWith('/public/stores/serieA/unlock', { password: 'secreto' })
    expect(res.data.capability).toBe('cap-abc')
    expect(storeCapability('serieA')).toBe('cap-abc')
  })

  it('sends the provided idempotency key plus capability on checkout', () => {
    sessionStorage.clear()
    setStoreCapability('serieA', 'cap-abc')
    publicStoreApi.checkout('serieA', { total: 200 }, 'ck-abc')

    const [, payload, config] = api.post.mock.calls[0]
    expect(api.post).toHaveBeenCalledWith('/stores/serieA/checkout', { total: 200 }, expect.anything())
    expect(payload).toEqual({ total: 200 })
    expect(config.headers).toMatchObject({
      'X-Cart-Token': expect.any(String),
      'X-Store-Capability': 'cap-abc',
      'Idempotency-Key': 'ck-abc',
    })
  })

  it('exposes payment, status and cancellation by tenant order reference with idempotency keys', () => {
    publicStoreApi.orderDetail('serieA', 'ORD/000001')
    publicStoreApi.orderStatus('serieA', 'ORD/000001')
    publicStoreApi.payOrder('serieA', 'ORD/000001')
    publicStoreApi.cancelOrder('serieA', 'ORD/000001')

    expect(api.get).toHaveBeenNthCalledWith(1, '/stores/serieA/orders/ORD%2F000001', expect.anything())
    expect(api.get).toHaveBeenNthCalledWith(2, '/stores/serieA/orders/ORD%2F000001/status', expect.anything())

    const [, , payConfig] = api.post.mock.calls[0]
    const [, , cancelConfig] = api.post.mock.calls[1]
    expect(api.post).toHaveBeenNthCalledWith(1, '/stores/serieA/orders/ORD%2F000001/pay', {}, expect.anything())
    expect(api.post).toHaveBeenNthCalledWith(2, '/stores/serieA/orders/ORD%2F000001/cancel', {}, expect.anything())
    expect(payConfig.headers['Idempotency-Key']).toMatch(/^pay-/)
    expect(cancelConfig.headers['Idempotency-Key']).toMatch(/^cancel-/)
    expect(payConfig.headers['Idempotency-Key']).not.toBe(cancelConfig.headers['Idempotency-Key'])
  })
})

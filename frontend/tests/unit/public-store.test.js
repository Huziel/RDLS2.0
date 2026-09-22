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
  ensureCartToken,
  lineTotal,
  money,
  resetCartToken,
  selectableStock,
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

  it('routes catalog, addons and theme based reads to public endpoints', () => {
    publicStoreApi.store('serieA')
    publicStoreApi.products('serieA', { category: 'Frios', per_page: 200 })
    publicStoreApi.product(8)

    expect(api.get).toHaveBeenNthCalledWith(1, '/public/stores/serieA')
    expect(api.get).toHaveBeenNthCalledWith(2, '/public/stores/serieA/products', {
      params: { category: 'Frios', per_page: 200 },
    })
    expect(api.get).toHaveBeenNthCalledWith(3, '/public/products/8')
  })

  it('sends a generated idempotency key on checkout', () => {
    const key = 'key-1'
    publicStoreApi.checkout('serieA', { total: 200 }, key)

    const [, payload, config] = api.post.mock.calls[0]
    expect(payload).toEqual({ total: 200 })
    expect(config.headers).toMatchObject({ 'X-Cart-Token': expect.any(String), 'Idempotency-Key': 'key-1' })
  })

  it('exposes payment, status and cancellation by order reference', () => {
    publicStoreApi.payOrder('serieA', 'ORD/000001')
    publicStoreApi.orderStatus('serieA', 'ORD/000001')
    publicStoreApi.cancelOrder('serieA', 'ORD/000001')
    publicStoreApi.orderDetail('ORD/000001')

    expect(api.post).toHaveBeenNthCalledWith(1, '/stores/serieA/orders/ORD%2F000001/pay', {}, expect.anything())
    expect(api.get).toHaveBeenNthCalledWith(1, '/stores/serieA/orders/ORD%2F000001/status', expect.anything())
    expect(api.post).toHaveBeenNthCalledWith(2, '/stores/serieA/orders/ORD%2F000001/cancel', {}, expect.anything())
    expect(api.get).toHaveBeenNthCalledWith(2, '/public/orders/ORD%2F000001', expect.anything())
  })
})
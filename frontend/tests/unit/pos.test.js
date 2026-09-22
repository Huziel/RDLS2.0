import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  normalizePosOrder,
  normalizePosPage,
  paymentLabel,
  posHistoryStats,
  posOrderTotal,
} from '../../src/utils/pos.js'

vi.mock('../../src/api/client.js', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '../../src/api/client.js'
import { posApi } from '../../src/api/pos.js'

describe('POS contract adapters', () => {
  it('normalizes legacy numeric fields and computes server-shaped totals', () => {
    const order = normalizePosOrder({
      id: '4',
      estado: '1',
      extra: '5',
      descuento: '3',
      details: [{ id: '8', cantidad: '2', precioBruto: '12.50', precioNeto: '25' }],
    })

    expect(order).toMatchObject({ id: 4, estado: 1, extra: 5, descuento: 3 })
    expect(order.details[0]).toMatchObject({ id: 8, cantidad: 2, precioBruto: 12.5, precioNeto: 25 })
    expect(posOrderTotal(order)).toBe(27)
    expect(posOrderTotal(order, 0, 50)).toBe(0)
  })

  it('normalizes Laravel paginator metadata', () => {
    expect(normalizePosPage({ data: [{ id: '1' }], current_page: 2, last_page: 3, total: 41 })).toMatchObject({
      rows: [{ id: 1 }],
      meta: { current_page: 2, last_page: 3, total: 41 },
    })
  })

  it('calculates history metrics and preserves the historical payment mapping', () => {
    const rows = [
      { total: 30, tipoPago: 1 },
      { total: '70', tipoPago: '2' },
      { total: 50, tipoPago: 3 },
    ]
    expect(posHistoryStats(rows)).toEqual({ count: 3, total: 150, average: 50, cash: 1, card: 1, transfer: 1 })
    expect([paymentLabel(1), paymentLabel(2), paymentLabel(3)]).toEqual(['Efectivo', 'Tarjeta', 'Transferencia'])
  })
})

describe('POS API service', () => {
  beforeEach(() => vi.clearAllMocks())

  it('uses the reconstructed order lifecycle endpoints', () => {
    posApi.orders()
    posApi.addProduct(3, { producto_id: 8, cantidad: 1 })
    posApi.saveOrder(3, { extra: 4 })
    posApi.payOrder(3, { tipo_pago: 'tarjeta', loyalty_points: 10 })

    expect(api.get).toHaveBeenCalledWith('/pos/orders')
    expect(api.post).toHaveBeenNthCalledWith(1, '/pos/orders/3/products', { producto_id: 8, cantidad: 1 })
    expect(api.post).toHaveBeenNthCalledWith(2, '/pos/orders/3/save', { extra: 4 })
    expect(api.post).toHaveBeenNthCalledWith(3, '/pos/orders/3/pay', { tipo_pago: 'tarjeta', loyalty_points: 10 })
  })

  it('passes history filters and scopes ticket lookup to its endpoint', () => {
    posApi.history({ from: '2026-09-01', payment: 'efectivo' })
    posApi.ticket('POS/1')

    expect(api.get).toHaveBeenNthCalledWith(1, '/pos/history', { params: { from: '2026-09-01', payment: 'efectivo' } })
    expect(api.get).toHaveBeenNthCalledWith(2, '/pos/ticket/POS%2F1')
  })
})

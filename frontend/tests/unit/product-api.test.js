import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/client.js', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '../../src/api/client.js'
import { productsApi } from '../../src/api/products.js'

describe('product and inventory API service', () => {
  beforeEach(() => vi.clearAllMocks())

  it('uses the confirmed list, category and barcode contracts', () => {
    productsApi.list({ page: 2, category: 'Flores' })
    productsApi.categories()
    productsApi.searchBarcode('750123')

    expect(api.get).toHaveBeenNthCalledWith(1, '/products', { params: { page: 2, category: 'Flores' } })
    expect(api.get).toHaveBeenNthCalledWith(2, '/categories')
    expect(api.get).toHaveBeenNthCalledWith(3, '/products/search-barcode', { params: { code: '750123' } })
  })

  it('updates absolute stock through the existing product contract', () => {
    productsApi.update(12, { stock: 7 })
    expect(api.put).toHaveBeenCalledWith('/products/12', { stock: 7 })
  })
})

import { describe, expect, it, vi } from 'vitest'
import { useProductList } from '../../src/composables/useProductList.js'
import {
  MAX_PRODUCT_IMAGE_BYTES,
  normalizeProduct,
  productListParams,
  productToForm,
  toProductPayload,
  validateProductForm,
  validateProductImage,
} from '../../src/utils/products.js'

describe('product contract adapters', () => {
  it('normalizes list and detail stock shapes', () => {
    expect(normalizeProduct({ id: '2', precio: '19.50', stock: '4', activo: 1 }).stock).toBe(4)
    expect(normalizeProduct({ id: 2, precio: 19.5, stock: { cantidad: 7 }, activo: 0 }).stock).toBe(7)
  })

  it('maps a product detail to editable fields', () => {
    expect(productToForm({
      nombre: 'Café',
      precio: '35.00',
      activo: true,
      stock: { cantidad: 3 },
      codigo_barras: 123,
      imagenes: ['one.jpg'],
    })).toMatchObject({
      nombre: 'Café',
      precio: '35.00',
      stock: 3,
      codigo_barras: '123',
      imagenes: ['one.jpg'],
    })
  })

  it('creates only the confirmed Laravel payload', () => {
    expect(toProductPayload({
      nombre: '  Producto  ',
      precio: '12.50',
      imagen: '',
      descripcion: ' Descripción ',
      variable: '',
      categoria: ' General ',
      activo: true,
      stock: '5',
      codigo_barras: '',
      imagenes: [' one.jpg ', '', 'two.jpg'],
    })).toEqual({
      nombre: 'Producto',
      precio: 12.5,
      imagen: null,
      descripcion: 'Descripción',
      variable: null,
      categoria: 'General',
      activo: true,
      stock: 5,
      codigo_barras: null,
      imagenes: ['one.jpg', 'two.jpg'],
    })
  })
})

describe('product validation', () => {
  it('matches required, numeric and stock constraints', () => {
    expect(validateProductForm({ nombre: '', precio: '', stock: -1 })).toEqual({
      nombre: 'El nombre es obligatorio.',
      precio: 'El precio es obligatorio.',
      stock: 'Las existencias deben ser un número entero mayor o igual a cero.',
    })
    expect(validateProductForm({ nombre: 'Válido', precio: 0, stock: 0 })).toEqual({})
  })

  it('enforces the confirmed image MIME and 10 MB limit', () => {
    expect(validateProductImage({ type: 'text/plain', size: 10 })).toContain('JPEG')
    expect(validateProductImage({ type: 'image/png', size: MAX_PRODUCT_IMAGE_BYTES + 1 })).toContain('10 MB')
    expect(validateProductImage({ type: 'image/webp', size: 100 })).toBe('')
  })

  it('only sends the active filter when selected', () => {
    expect(productListParams({ search: '', category: 'all', active: '' }, 2)).toEqual({
      page: 2,
      per_page: 20,
      search: '',
      category: 'all',
    })
    expect(productListParams({ search: 'café', category: 'Bebidas', active: '0' }, 1).active).toBe('0')
  })
})

describe('product list composable', () => {
  it('loads, normalizes and deletes products through the service', async () => {
    const service = {
      list: vi.fn().mockResolvedValue({
        data: [{ id: '1', nombre: 'Producto', precio: '10', stock: '2', activo: 1 }],
        meta: { current_page: 2, last_page: 3, total: 41 },
      }),
      categories: vi.fn().mockResolvedValue({ data: ['Bebidas', '', 'Comida'] }),
      remove: vi.fn().mockResolvedValue({ message: 'Producto eliminado.' }),
    }
    const state = useProductList(service)
    state.filters.search = 'Producto'

    await state.load(2)
    await state.loadCategories()
    expect(service.list).toHaveBeenCalledWith({ page: 2, per_page: 20, search: 'Producto', category: 'all' })
    expect(state.products.value[0]).toMatchObject({ id: 1, precio: 10, stock: 2, activo: true })
    expect(state.categories.value).toEqual(['Bebidas', 'Comida'])

    await state.remove(state.products.value[0])
    expect(service.remove).toHaveBeenCalledWith(1)
    expect(service.list).toHaveBeenLastCalledWith({ page: 1, per_page: 20, search: 'Producto', category: 'all' })
  })

  it('exposes API errors instead of leaving a blank list', async () => {
    const state = useProductList({
      list: vi.fn().mockRejectedValue({ message: 'Servicio no disponible.' }),
      categories: vi.fn(),
      remove: vi.fn(),
    })
    await state.load()
    expect(state.loading.value).toBe(false)
    expect(state.products.value).toEqual([])
    expect(state.error.value).toBe('Servicio no disponible.')
  })
})

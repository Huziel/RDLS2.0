import { reactive, ref } from 'vue'
import { productsApi } from '../api/products.js'
import { getApiErrorMessage } from '../utils/api-error.js'
import { normalizeProduct, productListParams } from '../utils/products.js'

export function useProductList(service = productsApi) {
  const products = ref([])
  const categories = ref([])
  const meta = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null })
  const loading = ref(false)
  const error = ref('')
  const deletingId = ref(null)
  const filters = reactive({ search: '', category: 'all', active: '' })
  let requestNumber = 0

  async function load(page = 1) {
    const currentRequest = ++requestNumber
    loading.value = true
    error.value = ''

    try {
      const response = await service.list(productListParams(filters, page))
      if (currentRequest !== requestNumber) return
      products.value = (response.data || []).map(normalizeProduct)
      meta.value = { ...meta.value, ...(response.meta || {}) }
    } catch (requestError) {
      if (currentRequest !== requestNumber) return
      products.value = []
      meta.value = { current_page: page, last_page: 1, total: 0, from: null, to: null }
      error.value = getApiErrorMessage(requestError, 'No fue posible cargar los productos.')
    } finally {
      if (currentRequest === requestNumber) loading.value = false
    }
  }

  async function loadCategories() {
    try {
      const response = await service.categories()
      categories.value = (response.data || []).filter((category) => category !== '')
    } catch {
      categories.value = []
    }
  }

  async function remove(product) {
    if (deletingId.value !== null) return false
    deletingId.value = product.id
    error.value = ''
    try {
      await service.remove(product.id)
      const currentPage = meta.value.current_page || 1
      const nextPage = products.value.length === 1 && currentPage > 1 ? currentPage - 1 : currentPage
      await load(nextPage)
      return true
    } catch (requestError) {
      error.value = getApiErrorMessage(requestError, 'No fue posible eliminar el producto.')
      return false
    } finally {
      deletingId.value = null
    }
  }

  return {
    products,
    categories,
    meta,
    loading,
    error,
    deletingId,
    filters,
    load,
    loadCategories,
    remove,
  }
}

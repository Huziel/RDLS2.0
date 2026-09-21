import { api } from './client.js'

export const productsApi = {
  list: (params = {}) => api.get('/products', { params }),
  categories: () => api.get('/categories'),
  get: (id) => api.get(`/products/${id}`),
  create: (payload) => api.post('/products', payload),
  update: (id, payload) => api.put(`/products/${id}`, payload),
  remove: (id) => api.delete(`/products/${id}`),
  addons: (productId) => api.get(`/products/${productId}/addons`),
  createAddon: (productId, payload) => api.post(`/products/${productId}/addons`, payload),
  updateAddon: (productId, addonId, payload) => api.put(`/products/${productId}/addons/${addonId}`, payload),
  removeAddon: (productId, addonId) => api.delete(`/products/${productId}/addons/${addonId}`),
  uploadImage: (file) => {
    const body = new FormData()
    body.append('file', file)
    return api.post('/upload/image', body, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
}

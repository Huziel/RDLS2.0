import { api } from './client.js'

export const posApi = {
  orders: () => api.get('/pos/orders'),
  createOrder: (payload) => api.post('/pos/orders', payload),
  addProduct: (orderId, payload) => api.post(`/pos/orders/${orderId}/products`, payload),
  updateProduct: (orderId, detailId, payload) => api.put(`/pos/orders/${orderId}/products/${detailId}`, payload),
  removeProduct: (orderId, detailId) => api.delete(`/pos/orders/${orderId}/products/${detailId}`),
  deleteOrder: (orderId) => api.delete(`/pos/orders/${orderId}`),
  saveOrder: (orderId, payload) => api.post(`/pos/orders/${orderId}/save`, payload),
  payOrder: (orderId, payload) => api.post(`/pos/orders/${orderId}/pay`, payload),
  history: (params = {}) => api.get('/pos/history', { params }),
  ticket: (folio) => api.get(`/pos/ticket/${encodeURIComponent(String(folio))}`),
  loyaltyCheck: (telefono) => api.post('/pos/loyalty/check', { telefono }),
}

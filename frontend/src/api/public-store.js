import { api } from './client.js'
import { cartHeaders, ensureCartToken } from '../utils/public-store.js'

export const publicStoreApi = {
  store: (serial) => api.get(`/public/stores/${serial}`),
  theme: (serial) => api.get(`/public/stores/${serial}/theme`),
  products: (serial, params = {}) => api.get(`/public/stores/${serial}/products`, { params }),
  product: (id) => api.get(`/public/products/${id}`),
  verifyPassword: (storeId, password) => api.post(`/catalog/${storeId}/verify-password`, { password }),

  cart: (serial, token = ensureCartToken()) =>
    api.get(`/stores/${serial}/cart`, { headers: cartHeaders(token) }),
  addToCart: (serial, payload, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/cart`, payload, { headers: cartHeaders(token) }),
  updateCartItem: (serial, cartId, quantity, token = ensureCartToken()) =>
    api.put(`/stores/${serial}/cart/${cartId}`, { quantity }, { headers: cartHeaders(token) }),
  removeCartItem: (serial, cartId, token = ensureCartToken()) =>
    api.delete(`/stores/${serial}/cart/${cartId}`, { headers: cartHeaders(token) }),
  clearCart: (serial, token = ensureCartToken()) =>
    api.delete(`/stores/${serial}/cart`, { headers: cartHeaders(token) }),
  applyCoupon: (serial, code, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/cart/coupon`, { code }, { headers: cartHeaders(token) }),

  checkout: (serial, payload, idempotencyKey, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/checkout`, payload, {
      headers: { ...cartHeaders(token), 'Idempotency-Key': idempotencyKey },
    }),
  cloneIdempotencyKey: () =>
    (crypto?.randomUUID?.() ?? `key-${Date.now()}-${Math.random().toString(36).slice(2)}`),

  orderDetail: (orderRef, token = ensureCartToken()) =>
    api.get(`/public/orders/${encodeURIComponent(String(orderRef))}`, { headers: cartHeaders(token) }),
  orderStatus: (serial, orderRef, token = ensureCartToken()) =>
    api.get(`/stores/${serial}/orders/${encodeURIComponent(String(orderRef))}/status`, { headers: cartHeaders(token) }),
  payOrder: (serial, orderRef, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/orders/${encodeURIComponent(String(orderRef))}/pay`, {}, { headers: cartHeaders(token) }),
  cancelOrder: (serial, orderRef, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/orders/${encodeURIComponent(String(orderRef))}/cancel`, {}, { headers: cartHeaders(token) }),
}
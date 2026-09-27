import { api } from './client.js'
import {
  cartHeaders,
  capabilityHeaders,
  ensureCartToken,
  orderIdempotencyKey,
  setStoreCapability,
  storeCapability,
  clearStoreCapability,
} from '../utils/public-store.js'

const scoped = (serial, orderRef) =>
  encodeURIComponent(String(serial)) + '/orders/' + encodeURIComponent(String(orderRef))

export const publicStoreApi = {
  store: (serial) => api.get(`/public/stores/${serial}`, { headers: capabilityHeaders(serial) }),
  theme: (serial) => api.get(`/public/stores/${serial}/theme`, { headers: capabilityHeaders(serial) }),
  products: (serial, params = {}) =>
    api.get(`/public/stores/${serial}/products`, { params, headers: capabilityHeaders(serial) }),
  product: (serial, id) =>
    api.get(`/public/stores/${serial}/products/${encodeURIComponent(String(id))}`, {
      headers: capabilityHeaders(serial),
    }),
  productAddons: (serial, id) =>
    api.get(`/public/stores/${serial}/products/${encodeURIComponent(String(id))}/addons`, {
      headers: capabilityHeaders(serial),
    }),
  unlock: async (serial, password) => {
    const response = await api.post(`/public/stores/${serial}/unlock`, { password })
    setStoreCapability(serial, response?.data?.capability)
    return response
  },

  storeCapability: (serial) => storeCapability(serial),
  clearStoreCapability: (serial) => clearStoreCapability(serial),

  cart: (serial, token = ensureCartToken()) =>
    api.get(`/stores/${serial}/cart`, { headers: capabilityHeaders(serial, token) }),
  addToCart: (serial, payload, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/cart`, payload, { headers: capabilityHeaders(serial, token) }),
  updateCartItem: (serial, cartId, quantity, token = ensureCartToken()) =>
    api.put(`/stores/${serial}/cart/${cartId}`, { quantity }, { headers: capabilityHeaders(serial, token) }),
  removeCartItem: (serial, cartId, token = ensureCartToken()) =>
    api.delete(`/stores/${serial}/cart/${cartId}`, { headers: capabilityHeaders(serial, token) }),
  clearCart: (serial, token = ensureCartToken()) =>
    api.delete(`/stores/${serial}/cart`, { headers: capabilityHeaders(serial, token) }),
  applyCoupon: (serial, code, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/cart/coupon`, { code }, { headers: capabilityHeaders(serial, token) }),

  checkout: (serial, payload, idempotencyKey, token = ensureCartToken()) =>
    api.post(`/stores/${serial}/checkout`, payload, {
      headers: { ...capabilityHeaders(serial, token), 'Idempotency-Key': idempotencyKey },
    }),

  // Las rutas de orden NO llevan capability: se autorizan por la sesion
  // X-Cart-Token + locks internos del servidor.
  orderDetail: (serial, orderRef, token = ensureCartToken()) =>
    api.get(`/stores/${scoped(serial, orderRef)}`, { headers: cartHeaders(token) }),
  orderStatus: (serial, orderRef, token = ensureCartToken()) =>
    api.get(`/stores/${scoped(serial, orderRef)}/status`, { headers: cartHeaders(token) }),
  payOrder: (serial, orderRef, token = ensureCartToken()) =>
    api.post(`/stores/${scoped(serial, orderRef)}/pay`, {}, {
      headers: { ...cartHeaders(token), 'Idempotency-Key': orderIdempotencyKey('pay', serial, orderRef) },
    }),
  cancelOrder: (serial, orderRef, token = ensureCartToken()) =>
    api.post(`/stores/${scoped(serial, orderRef)}/cancel`, {}, {
      headers: { ...cartHeaders(token), 'Idempotency-Key': orderIdempotencyKey('cancel', serial, orderRef) },
    }),
}
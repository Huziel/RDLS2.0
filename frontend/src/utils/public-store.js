const CART_TOKEN_KEY = 'cart_token'

export function cartToken() {
  return localStorage.getItem(CART_TOKEN_KEY) || sessionStorage.getItem(CART_TOKEN_KEY) || ''
}

export function ensureCartToken() {
  let token = cartToken()
  if (token) return token

  token = (crypto?.randomUUID?.() ?? `cart-${Date.now()}-${Math.random().toString(36).slice(2)}`)
  localStorage.setItem(CART_TOKEN_KEY, token)
  return token
}

export function cartHeaders(token = ensureCartToken()) {
  return { 'X-Cart-Token': token }
}

export function resetCartToken() {
  localStorage.removeItem(CART_TOKEN_KEY)
  sessionStorage.removeItem(CART_TOKEN_KEY)
}

export function money(value) {
  const amount = Number(value ?? 0)
  return `$${(Number.isFinite(amount) ? amount : 0).toFixed(2)}`
}

export function lineTotal(line) {
  return Number(line?.price ?? 0)
}

export function cartSubtotal(items = []) {
  return items.reduce((sum, item) => sum + lineTotal(item), 0)
}

export function cartCount(items = []) {
  return items.reduce((sum, item) => sum + Number(item?.quantity ?? 0), 0)
}

export function selectableStock(stock) {
  const value = Number(stock ?? 0)
  return Number.isFinite(value) ? Math.max(0, value) : 0
}
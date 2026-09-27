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

const CAPABILITY_PREFIX = 'ps-cap:'

function capabilityKey(serial) {
  return `${CAPABILITY_PREFIX}${String(serial ?? '').trim().toLowerCase()}`
}

export function storeCapability(serial) {
  return serial ? (sessionStorage.getItem(capabilityKey(serial)) || '') : ''
}

export function setStoreCapability(serial, capability) {
  if (!serial) return
  if (capability) sessionStorage.setItem(capabilityKey(serial), capability)
  else clearStoreCapability(serial)
}

export function clearStoreCapability(serial) {
  if (!serial) return
  sessionStorage.removeItem(capabilityKey(serial))
}

export function capabilityHeaders(serial, token = ensureCartToken()) {
  const headers = cartHeaders(token)
  const capability = storeCapability(serial)
  if (capability) headers['X-Store-Capability'] = capability
  return headers
}

// Dos pasadas FNV-1a (64 bits combinados) en base36. NO criptográfico: sirve solo para
// que la misma accion+contenido genere SIEMPRE la misma Idempotency-Key HTTP
// (el backend deriva su sha256 interno del contenido real, no de esta clave).
function stableHash(input) {
  let forward = 0x811c9dc5
  let reverse = 0x9e3779b9
  for (let i = 0; i < input.length; i++) {
    forward ^= input.charCodeAt(i)
    forward = Math.imul(forward, 0x01000193)
    reverse ^= input.charCodeAt(input.length - 1 - i)
    reverse = Math.imul(reverse, 0x01000193)
  }
  return `${(forward >>> 0).toString(36)}${(reverse >>> 0).toString(36)}`
}

function stableSerialize(value) {
  if (Array.isArray(value)) return `[${value.map(stableSerialize).join(',')}]`
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableSerialize(value[key])}`).join(',')}}`
  }
  return JSON.stringify(value ?? null)
}

// La clave de checkout debe cambiar si cambia el carrito, el metodo de pago,
// el envio o la direccion: asi el backend nunca ve una clave ya usada con un
// contenido distinto (que devolveria 409 IdempotencyConflict).
export function checkoutIdempotencyKey(serial, payload = {}, items = [], token = ensureCartToken()) {
  const cartParts = (items || [])
    .map((item) => {
      const addons = (item?.addons ?? [])
        .map((addon) => ({ id: addon?.id ?? null, quantity: Number(addon?.quantity ?? 1) }))
        .sort((a, b) => stableSerialize(a).localeCompare(stableSerialize(b)))
      return {
        id: item?.id ?? null,
        product_id: item?.product_id ?? null,
        quantity: Number(item?.quantity ?? 0),
        addons,
      }
    })
    .sort((a, b) => stableSerialize(a).localeCompare(stableSerialize(b)))
  return `ck-${stableHash(stableSerialize({ serial, token, payload, items: cartParts }))}`
}

export function orderIdempotencyKey(operation, serial, orderRef) {
  return `${operation}-${stableHash(`${serial}|${String(orderRef ?? '').trim()}`)}`
}

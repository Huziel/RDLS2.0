import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  timeout: 15000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = sessionStorage.getItem('token') || localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// Rutas públicas de cliente que PUEDEN devolver 401/403 sin que eso signifique
// sesión de propietario inválida: catálogos públicos, carrito/checkout/órdenes
// con X-Cart-Token, y páginas públicas. Aquí NO se expulsa al usuario.
const PUBLIC_CLIENT_PREFIXES = ['/public/', '/stores/', '/qr/', '/pages/']

const isPublicClientRoute = (config) => {
  const url = config?.url ?? ''
  return PUBLIC_CLIENT_PREFIXES.some((prefix) => url.startsWith(prefix))
}

api.interceptors.response.use(
  (response) => response.data,
  (error) => {
    const status = error.response?.status
    const payload = error.response?.data
    const fallbackMessage = error.code === 'ECONNABORTED'
      ? 'La solicitud tardó demasiado. Intenta nuevamente.'
      : error.response
        ? error.message
        : 'No fue posible conectar con el servidor.'
    const normalized = payload && typeof payload === 'object'
      ? { ...payload, status, code: error.code }
      : { message: fallbackMessage || 'No se pudo completar la solicitud.', status, code: error.code }

    if (status === 401 && !error.config?.url?.includes('/auth/login') && !isPublicClientRoute(error.config)) {
      window.dispatchEvent(new Event('auth:unauthorized'))
    }

    return Promise.reject(normalized)
  },
)

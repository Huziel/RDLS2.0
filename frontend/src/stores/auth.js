import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authApi } from '../api/auth.js'
import { getApiErrorMessage } from '../utils/api-error.js'

function readStoredUser() {
  try {
    return JSON.parse(sessionStorage.getItem('user') || localStorage.getItem('user'))
  } catch {
    localStorage.removeItem('user')
    sessionStorage.removeItem('user')
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(readStoredUser())
  const token = ref(sessionStorage.getItem('token') || localStorage.getItem('token'))
  const loading = ref(false)
  const initialized = ref(false)
  const error = ref('')

  const isAuthenticated = computed(() => Boolean(token.value))
  const userType = computed(() => String(user.value?.type || ''))
  const permissions = computed(() => user.value?.permissions || [])
  const roles = computed(() => user.value?.roles || [])

  function persistSession(payload, remember) {
    clearSession()
    const storage = remember ? localStorage : sessionStorage
    user.value = payload.user
    token.value = payload.token
    storage.setItem('user', JSON.stringify(payload.user))
    storage.setItem('token', payload.token)
  }

  function clearSession() {
    user.value = null
    token.value = null
    localStorage.removeItem('user')
    localStorage.removeItem('token')
    sessionStorage.removeItem('user')
    sessionStorage.removeItem('token')
  }

  async function login(identifier, password, remember = false) {
    loading.value = true
    error.value = ''

    try {
      const response = await authApi.login({ email: identifier, password, remember })
      persistSession(response.data, remember)

      if (remember) localStorage.setItem('remembered_email', identifier)
      else localStorage.removeItem('remembered_email')

      return response.data.user
    } catch (requestError) {
      error.value = getApiErrorMessage(requestError, 'No fue posible iniciar sesión.')
      throw requestError
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      if (token.value) await authApi.logout()
    } finally {
      clearSession()
    }
  }

  async function bootstrap() {
    if (initialized.value) return

    if (token.value) {
      try {
        const response = await authApi.currentUser()
        user.value = response.data
        const storage = sessionStorage.getItem('token') ? sessionStorage : localStorage
        storage.setItem('user', JSON.stringify(response.data))
      } catch (requestError) {
        if (requestError?.status === 401) clearSession()
      }
    }

    initialized.value = true
  }

  function can(permission) {
    return permissions.value.includes(permission)
  }

  function hasRole(role) {
    return roles.value.includes(role)
  }

  function clearError() {
    error.value = ''
  }

  return {
    user,
    token,
    loading,
    initialized,
    error,
    isAuthenticated,
    userType,
    permissions,
    roles,
    login,
    logout,
    bootstrap,
    can,
    hasRole,
    clearError,
    clearSession,
  }
})

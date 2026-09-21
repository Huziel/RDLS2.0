<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'
import { homeForUserType } from '../router/guards.js'
import { getFieldErrors } from '../utils/api-error.js'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const fields = reactive({ identifier: '', password: '', remember: false })
const fieldErrors = ref({})
const legacyBaseUrl = import.meta.env.VITE_LEGACY_APP_URL || 'http://127.0.0.1:8000'
const forgotPasswordUrl = `${legacyBaseUrl}/forgot-password`
const registerUrl = `${legacyBaseUrl}/register`

onMounted(() => {
  const remembered = localStorage.getItem('remembered_email')
  if (remembered) {
    fields.identifier = remembered
    fields.remember = true
  }
})

async function submit() {
  auth.clearError()
  fieldErrors.value = {}

  try {
    const user = await auth.login(fields.identifier, fields.password, fields.remember)
    const destination = typeof route.query.redirect === 'string'
      ? route.query.redirect
      : homeForUserType(user.type)
    await router.replace(destination)
  } catch (error) {
    fieldErrors.value = getFieldErrors(error)
  }
}
</script>

<template>
  <form class="auth-form" novalidate @submit.prevent="submit">
    <div v-if="auth.error" class="alert alert-error" role="alert">{{ auth.error }}</div>

    <label class="field">
      <span>Correo electrónico o teléfono</span>
      <input
        v-model.trim="fields.identifier"
        name="email"
        autocomplete="username"
        placeholder="correo@ejemplo.com"
        required
        :aria-invalid="Boolean(fieldErrors.email)"
      />
      <small v-if="fieldErrors.email" class="field-error">{{ fieldErrors.email[0] }}</small>
    </label>

    <label class="field">
      <span>Contraseña</span>
      <input
        v-model="fields.password"
        name="password"
        type="password"
        autocomplete="current-password"
        placeholder="••••••••"
        required
        :aria-invalid="Boolean(fieldErrors.password)"
      />
      <small v-if="fieldErrors.password" class="field-error">{{ fieldErrors.password[0] }}</small>
    </label>

    <div class="form-options">
      <label class="checkbox"><input v-model="fields.remember" type="checkbox" /> Recordarme</label>
      <a :href="forgotPasswordUrl">¿Olvidaste tu contraseña?</a>
    </div>

    <button class="button button-primary button-block" :disabled="auth.loading">
      {{ auth.loading ? 'Iniciando sesión...' : 'Iniciar sesión' }}
    </button>

    <p class="form-footer">
      ¿No tienes cuenta?
      <a :href="registerUrl">Regístrate</a>
    </p>
  </form>
</template>

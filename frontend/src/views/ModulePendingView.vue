<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const title = computed(() => route.meta.title || 'Módulo')
const legacyUrl = computed(() => `${import.meta.env.VITE_LEGACY_APP_URL || 'http://127.0.0.1:8000'}${route.fullPath}`)

function continueOnboarding() {
  localStorage.setItem('onboarding_done', 'true')
  window.location.assign('/dashboard')
}
</script>

<template>
  <main class="pending-page">
    <p class="eyebrow">MIGRACIÓN POR ETAPAS</p>
    <h1>{{ title }}</h1>
    <p>Este módulo está inventariado y pendiente de reconstrucción. La versión compilada continúa disponible durante la transición.</p>
    <button v-if="route.name === 'onboarding'" class="button button-primary" @click="continueOnboarding">Continuar al panel</button>
    <a v-else class="button button-primary" :href="legacyUrl">Abrir versión actual</a>
  </main>
</template>

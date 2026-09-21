<script setup>
import { computed, onMounted, ref } from 'vue'
import { getPublicSiteSettings } from '../api/site.js'

const settings = ref(null)

const brand = computed(() => settings.value?.site_name || 'Ruta de la Seda')
const colors = computed(() => ({
  '--auth-start': settings.value?.login_colors?.bg_start || '#172554',
  '--auth-end': settings.value?.login_colors?.bg_end || '#312e81',
  '--auth-card': settings.value?.login_colors?.card_bg || '#ffffff',
  '--auth-primary': settings.value?.login_colors?.primary || '#4f46e5',
  '--auth-text': settings.value?.login_colors?.text || '#172033',
}))

onMounted(async () => {
  try {
    const response = await getPublicSiteSettings()
    settings.value = response.data
  } catch {
    settings.value = null
  }
})
</script>

<template>
  <main class="auth-layout" :style="colors">
    <section class="auth-card">
      <header class="auth-header">
        <p class="eyebrow">PLATAFORMA COMERCIAL</p>
        <h1>{{ brand }}</h1>
        <p>Catálogos digitales inteligentes</p>
      </header>
      <RouterView />
    </section>
  </main>
</template>

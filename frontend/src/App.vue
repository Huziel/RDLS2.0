<script setup>
import { onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getPublicSiteSettings } from './api/site.js'

const route = useRoute()

function applyGlobalBranding(settings) {
  const name = settings?.site_name || 'Ruta de la Seda'
  document.title = name
  sessionStorage.setItem('siteName', name)

  if (settings?.site_favicon) {
    let favicon = document.querySelector("link[rel='icon']")
    if (!favicon) {
      favicon = document.createElement('link')
      favicon.rel = 'icon'
      document.head.appendChild(favicon)
    }
    favicon.href = settings.site_favicon
    sessionStorage.setItem('siteFavicon', settings.site_favicon)
  }
}

onMounted(async () => {
  try {
    const response = await getPublicSiteSettings()
    applyGlobalBranding(response.data)
  } catch {
    applyGlobalBranding()
  }
})

watch(
  () => route.path,
  (path) => {
    if (path.startsWith('/store/')) return
    document.title = sessionStorage.getItem('siteName') || 'Ruta de la Seda'
  },
)
</script>

<template>
  <RouterView />
</template>

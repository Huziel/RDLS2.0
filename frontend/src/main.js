import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router/index.js'
import { useAuthStore } from './stores/auth.js'
import './styles/main.css'
import './styles/products.css'
import './styles/pos.css'
import './styles/public-store.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
window.addEventListener('auth:unauthorized', () => {
  const auth = useAuthStore(pinia)
  const redirect = router.currentRoute.value.fullPath
  auth.clearSession()
  if (router.currentRoute.value.name !== 'login') {
    router.replace({ name: 'login', query: { redirect } })
  }
})
app.use(router)
app.mount('#app')

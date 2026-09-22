<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'
import { storeApi } from '../api/store.js'

const auth = useAuthStore()
const router = useRouter()
const menuOpen = ref(false)
const store = ref(null)
const extra = ref(null)
const subscription = ref(null)

const storeName = computed(() => extra.value?.nombre_tienda || store.value?.serial || 'Mi tienda')
const publicStoreUrl = computed(() => store.value?.serial ? `/store/${store.value.serial}` : '/')

const navigationItems = [
  { to: '/dashboard', label: 'Panel', exact: true, permission: 'dashboard.view' },
  { to: '/dashboard/products', label: 'Productos', permission: 'products.read' },
  { to: '/dashboard/orders', label: 'Pedidos' },
  { to: '/dashboard/pos', label: 'Punto de venta', exact: true, permission: 'pos.use' },
  { to: '/dashboard/pos/history', label: 'Historial POS', permission: 'pos.history' },
  { to: '/dashboard/appointments', label: 'Agenda' },
  { to: '/dashboard/crm', label: 'CRM' },
  { to: '/dashboard/analytics', label: 'Analíticas' },
  { to: '/dashboard/settings', label: 'Configuración' },
]
const navigation = computed(() => navigationItems.filter((item) => (
  !item.permission || auth.hasRole('super-admin') || auth.can(item.permission)
)))

onMounted(async () => {
  const results = await Promise.allSettled([
    storeApi.get(),
    storeApi.getExtra(),
    storeApi.getSubscription(),
  ])
  if (results[0].status === 'fulfilled') store.value = results[0].value.data
  if (results[1].status === 'fulfilled') extra.value = results[1].value.data
  if (results[2].status === 'fulfilled') subscription.value = results[2].value.data
})

async function logout() {
  await auth.logout()
  await router.replace('/login')
}
</script>

<template>
  <div class="dashboard-shell">
    <button class="menu-toggle" type="button" :aria-label="menuOpen ? 'Cerrar menú' : 'Abrir menú'" aria-controls="dashboard-navigation" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">Menú</button>
    <div v-if="menuOpen" class="sidebar-backdrop" @click="menuOpen = false" />

    <aside id="dashboard-navigation" class="sidebar" :class="{ open: menuOpen }">
      <div class="sidebar-brand">
        <img v-if="store?.logo" :src="store.logo" alt="" />
        <span v-else class="brand-mark">RS</span>
        <div><strong>{{ storeName }}</strong><small>{{ subscription?.plan_name || 'Panel comercial' }}</small></div>
      </div>

      <nav aria-label="Navegación principal">
        <RouterLink
          v-for="item in navigation"
          :key="item.to"
          :to="item.to"
          :class="{ active: item.exact ? $route.path === item.to : $route.path.startsWith(item.to) }"
          @click="menuOpen = false"
        >
          {{ item.label }}
        </RouterLink>
      </nav>

      <div class="sidebar-footer">
        <a class="store-preview" :href="publicStoreUrl" target="_blank" rel="noreferrer">Ver tienda pública</a>
        <span>{{ auth.user?.name }}</span>
        <button type="button" @click="logout">Cerrar sesión</button>
      </div>
    </aside>

    <main class="dashboard-main">
      <RouterView />
    </main>
  </div>
</template>

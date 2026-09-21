<script setup>
import { computed, onMounted, ref } from 'vue'
import { getDashboardStats } from '../../api/dashboard.js'
import { useAuthStore } from '../../stores/auth.js'
import { getApiErrorMessage } from '../../utils/api-error.js'

const auth = useAuthStore()
const loading = ref(true)
const error = ref('')
const stats = ref({})

const cards = computed(() => [
  { label: 'Productos activos', value: stats.value.active_products ?? 0, detail: `${stats.value.products ?? 0} registrados`, tone: 'indigo' },
  { label: 'Ventas en línea', value: money(stats.value.online_revenue), detail: `${stats.value.online_orders ?? 0} pedidos`, tone: 'emerald' },
  { label: 'Ventas POS', value: money(stats.value.pos_revenue), detail: `${stats.value.pos_orders ?? 0} ventas`, tone: 'amber' },
  { label: 'Ingreso de hoy', value: money(stats.value.today_revenue), detail: `${stats.value.today_appointments ?? 0} citas`, tone: 'rose' },
])

function money(value) {
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value || 0))
}

onMounted(async () => {
  try {
    const response = await getDashboardStats()
    stats.value = response.data
  } catch (requestError) {
    error.value = getApiErrorMessage(requestError, 'No pudimos cargar el resumen.')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="dashboard-page">
    <header class="page-heading">
      <div>
        <p class="eyebrow">RESUMEN DEL NEGOCIO</p>
        <h1>Panel de control</h1>
        <p>Ventas, inventario y actividad reciente en un solo lugar.</p>
      </div>
      <RouterLink v-if="auth.hasRole('super-admin') || auth.can('products.create')" class="button button-primary" to="/dashboard/products/create">Nuevo producto</RouterLink>
    </header>

    <div v-if="loading" class="page-state">Cargando indicadores...</div>
    <div v-else-if="error" class="alert alert-error">{{ error }}</div>
    <template v-else>
      <div class="stats-grid">
        <article v-for="card in cards" :key="card.label" class="stat-card" :class="card.tone">
          <span>{{ card.label }}</span><strong>{{ card.value }}</strong><small>{{ card.detail }}</small>
        </article>
      </div>

      <div class="dashboard-grid">
        <article class="panel">
          <div class="panel-heading"><h2>Pedidos recientes</h2><RouterLink to="/dashboard/orders">Ver todos</RouterLink></div>
          <div v-if="!stats.recent_online?.length" class="empty-state">Todavía no hay pedidos en línea.</div>
          <ul v-else class="activity-list">
            <li v-for="order in stats.recent_online" :key="order.id">
              <div><strong>{{ order.order }}</strong><span>{{ order.nombre }}</span></div>
              <b>{{ money(order.total) }}</b>
            </li>
          </ul>
        </article>

        <article class="panel">
          <div class="panel-heading"><h2>Actividad POS</h2><RouterLink to="/dashboard/pos/history">Historial</RouterLink></div>
          <div v-if="!stats.recent_pos?.length" class="empty-state">Todavía no hay ventas en punto de venta.</div>
          <ul v-else class="activity-list">
            <li v-for="order in stats.recent_pos" :key="order.id">
              <div><strong>{{ order.order }}</strong><span>{{ order.nombre }}</span></div>
              <b>{{ money(order.total) }}</b>
            </li>
          </ul>
        </article>
      </div>
    </template>
  </section>
</template>

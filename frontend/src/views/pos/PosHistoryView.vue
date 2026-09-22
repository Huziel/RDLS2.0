<script setup>
import { computed, onMounted, ref } from 'vue'
import { posApi } from '../../api/pos.js'
import { formatProductPrice } from '../../utils/products.js'
import { normalizePosPage, paymentLabel, posHistoryStats } from '../../utils/pos.js'
import { useAuthStore } from '../../stores/auth.js'

const auth = useAuthStore()
const rows = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const from = ref('')
const to = ref('')
const payment = ref('all')
const expanded = ref(null)
const loading = ref(true)
const error = ref('')
const backendStats = ref(null)
const stats = computed(() => backendStats.value || posHistoryStats(rows.value))

onMounted(() => load(1))

async function load(page = 1) {
  loading.value = true
  error.value = ''
  rows.value = []
  backendStats.value = null
  expanded.value = null
  try {
    const response = await posApi.history({ page, per_page: 20, from: from.value || undefined, to: to.value || undefined, payment: payment.value === 'all' ? undefined : payment.value })
    const normalized = normalizePosPage(response)
    rows.value = normalized.rows
    meta.value = normalized.meta
    backendStats.value = response.stats || null
  } catch (exception) {
    error.value = exception.message || 'No fue posible cargar el historial.'
  } finally {
    loading.value = false
  }
}

function clearFilters() {
  from.value = ''
  to.value = ''
  payment.value = 'all'
  load(1)
}

function printList() {
  window.print()
}
</script>

<template>
  <section class="pos-page pos-history-page">
    <header class="page-heading pos-heading">
      <div><p class="eyebrow">CIERRE Y CONTROL</p><h1>Historial POS</h1><p>Consulta ventas terminadas, métodos de pago y tickets.</p></div>
      <RouterLink v-if="auth.hasRole('super-admin') || auth.can('pos.use')" class="button button-primary" to="/dashboard/pos">Abrir punto de venta</RouterLink>
    </header>

    <div v-if="!error" class="stats-grid pos-stats">
      <article class="stat-card indigo"><span>Total vendido</span><strong>{{ formatProductPrice(stats.total) }}</strong><small>{{ stats.count }} ventas filtradas</small></article>
      <article class="stat-card emerald"><span>Ticket promedio</span><strong>{{ formatProductPrice(stats.average) }}</strong><small>Sobre el periodo filtrado</small></article>
      <article class="stat-card amber"><span>Efectivo / tarjeta</span><strong>{{ stats.cash }} / {{ stats.card }}</strong><small>Ventas por método</small></article>
      <article class="stat-card rose"><span>Transferencias</span><strong>{{ stats.transfer }}</strong><small>Ventas registradas</small></article>
    </div>

    <form class="pos-history-filters panel" @submit.prevent="load(1)">
      <label><span>Desde</span><input v-model="from" type="date" /></label>
      <label><span>Hasta</span><input v-model="to" type="date" /></label>
      <label><span>Método</span><select v-model="payment"><option value="all">Todos</option><option value="efectivo">Efectivo</option><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option></select></label>
      <button class="button button-primary" type="submit" :disabled="loading">Filtrar</button>
      <button class="button button-secondary" type="button" :disabled="loading" @click="clearFilters">Limpiar</button>
    </form>

    <div v-if="error" class="alert alert-error product-error" role="alert"><span>{{ error }}</span><button type="button" @click="load(meta.current_page)">Reintentar</button></div>
    <div v-else-if="loading" class="product-loading"><span class="product-spinner" /> Cargando ventas...</div>
    <div v-else-if="!rows.length" class="product-empty"><strong>No hay ventas para estos filtros</strong><p>Prueba otro rango o registra una nueva venta.</p></div>
    <section v-else class="pos-history-list">
      <article v-for="order in rows" :key="order.id" class="panel pos-history-card">
        <button type="button" class="pos-history-summary" :aria-expanded="expanded === order.id" @click="expanded = expanded === order.id ? null : order.id">
          <span><small>Folio</small><strong>{{ order.noOrder }}</strong></span>
          <span><small>Cliente</small><strong>{{ order.nombre }}</strong></span>
          <span><small>Fecha</small><strong>{{ order.fecha }}</strong></span>
          <span><small>Pago</small><strong>{{ paymentLabel(order.tipoPago) }}</strong></span>
          <span><small>Total</small><strong>{{ formatProductPrice(order.total) }}</strong></span>
          <b>{{ expanded === order.id ? 'Ocultar' : 'Detalle' }}</b>
        </button>
        <div v-if="expanded === order.id" class="pos-history-detail">
          <ul><li v-for="line in order.details" :key="line.id"><span>{{ line.cantidad }} × {{ line.nameProd }}</span><strong>{{ formatProductPrice(line.precioNeto) }}</strong></li></ul>
          <dl><div v-if="order.extra"><dt>Extra</dt><dd>{{ formatProductPrice(order.extra) }}</dd></div><div v-if="order.descuento"><dt>Descuento</dt><dd>-{{ formatProductPrice(order.descuento) }}</dd></div><div><dt>Total</dt><dd>{{ formatProductPrice(order.total) }}</dd></div></dl>
          <button class="button button-secondary button-small" type="button" @click="printList">Imprimir ticket</button>
        </div>
      </article>
    </section>

    <nav v-if="!error && !loading && meta.last_page > 1" class="product-pagination" aria-label="Paginación del historial">
      <button type="button" :disabled="loading || meta.current_page <= 1" @click="load(meta.current_page - 1)">Anterior</button>
      <span>Página {{ meta.current_page }} de {{ meta.last_page }} · {{ meta.total }} ventas</span>
      <button type="button" :disabled="loading || meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Siguiente</button>
    </nav>
  </section>
</template>

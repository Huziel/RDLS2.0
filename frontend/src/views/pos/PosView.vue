<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PosOrderPanel from '../../components/pos/PosOrderPanel.vue'
import PosProductCatalog from '../../components/pos/PosProductCatalog.vue'
import { posApi } from '../../api/pos.js'
import { productsApi } from '../../api/products.js'
import { normalizeProduct } from '../../utils/products.js'
import { normalizePosOrder, posOrderTotal } from '../../utils/pos.js'
import { useAuthStore } from '../../stores/auth.js'

const auth = useAuthStore()
const orders = ref([])
const products = ref([])
const selectedId = ref(null)
const search = ref('')
const category = ref('all')
const barcode = ref('')
const customerName = ref('Venta mostrador')
const customerPhone = ref('')
const extra = ref(0)
const paymentMethod = ref('efectivo')
const loyalty = ref(null)
const loyaltyPoints = ref(0)
const receipt = ref(null)
const receiptClose = ref(null)
const newOrderButton = ref(null)
const focusBeforeReceipt = ref(null)
const loading = ref(true)
const productLoading = ref(true)
const busy = ref(false)
const refreshing = ref(false)
const error = ref('')
const notice = ref('')

const selectedOrder = computed(() => orders.value.find((order) => order.id === selectedId.value) || null)
const locked = computed(() => busy.value || refreshing.value)
const categories = computed(() => [...new Set(products.value.map((product) => product.categoria).filter(Boolean))].sort())
const filteredProducts = computed(() => {
  const term = search.value.trim().toLocaleLowerCase('es')
  return products.value.filter((product) => {
    const matchesCategory = category.value === 'all' || product.categoria === category.value
    const matchesSearch = !term || [product.nombre, product.codigo_barras, product.categoria]
      .some((value) => String(value || '').toLocaleLowerCase('es').includes(term))
    return matchesCategory && matchesSearch
  })
})
const estimatedDiscount = computed(() => {
  if (!loyalty.value?.registered || !loyaltyPoints.value) return 0
  return Math.floor(loyaltyPoints.value / Number(loyalty.value.pesos_per_point || 1))
})

onMounted(load)
onBeforeUnmount(releaseDialog)

watch(receipt, async (value) => {
  const shell = document.querySelector('.dashboard-shell')
  if (value) {
    focusBeforeReceipt.value = document.activeElement
    shell?.setAttribute('inert', '')
    await nextTick()
    receiptClose.value?.focus()
  } else {
    shell?.removeAttribute('inert')
    await nextTick()
    const target = focusBeforeReceipt.value
    if (target instanceof HTMLElement && target.isConnected) target.focus()
    else newOrderButton.value?.focus()
  }
})

async function fetchCatalog() {
  const catalog = []
  let page = 1
  let lastPage = 1
  do {
    const response = await productsApi.list({ active: 1, per_page: 100, page })
    catalog.push(...(response.data || []).map(normalizeProduct))
    lastPage = Number(response.meta?.last_page || 1)
    page += 1
  } while (page <= lastPage)

  return catalog
}

async function load() {
  loading.value = true
  productLoading.value = true
  error.value = ''
  const [orderResult, productResult] = await Promise.allSettled([
    posApi.orders(),
    fetchCatalog(),
  ])
  if (orderResult.status === 'fulfilled') {
    orders.value = (orderResult.value.data || []).map(normalizePosOrder)
    if (!orders.value.some((order) => order.id === selectedId.value)) selectedId.value = orders.value[0]?.id || null
  } else {
    error.value = orderResult.reason.message || 'No fue posible cargar las órdenes.'
  }
  if (productResult.status === 'fulfilled') {
    products.value = productResult.value
  } else {
    error.value ||= productResult.reason.message || 'No fue posible cargar los productos.'
  }
  loading.value = false
  productLoading.value = false
  syncOrderState()
}

async function reloadOrders(preferredId = selectedId.value, preserveExtra = false) {
  const previousId = selectedId.value
  const previousExtra = extra.value
  const response = await posApi.orders()
  orders.value = (response.data || []).map(normalizePosOrder)
  selectedId.value = orders.value.some((order) => order.id === preferredId) ? preferredId : orders.value[0]?.id || null
  syncOrderState()
  if (preserveExtra && selectedId.value === previousId && selectedOrder.value?.estado === 0) extra.value = previousExtra
}

function syncOrderState() {
  extra.value = selectedOrder.value?.extra || 0
  loyalty.value = null
  loyaltyPoints.value = 0
}

function selectOrder(id) {
  selectedId.value = id
  syncOrderState()
}

async function runMutation(callback, successMessage = '') {
  if (locked.value) return null
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await callback()
    notice.value = successMessage
    return result
  } catch (exception) {
    error.value = Object.values(exception.errors || {})[0]?.[0] || exception.message || 'No fue posible completar la operación.'
    return null
  } finally {
    busy.value = false
  }
}

async function refreshOrders(preferredId, preserveExtra = false) {
  refreshing.value = true
  try {
    await reloadOrders(preferredId, preserveExtra)
    return true
  } catch (exception) {
    error.value = exception.message || 'La operación terminó, pero no fue posible actualizar las órdenes.'
    return false
  } finally {
    refreshing.value = false
  }
}

async function createOrder() {
  const name = customerName.value.trim()
  if (!name) {
    error.value = 'Escribe el nombre del cliente o usa Venta mostrador.'
    return
  }
  const response = await runMutation(
    () => posApi.createOrder({ nombre: name, telefono: customerPhone.value.trim() || null }),
    'Orden creada.',
  )
  if (!response) return
  customerName.value = 'Venta mostrador'
  customerPhone.value = ''
  await refreshOrders(Number(response.data.id))
}

async function addProduct(product) {
  if (!selectedOrder.value) {
    error.value = 'Crea o selecciona una orden antes de agregar productos.'
    return
  }
  if (selectedOrder.value.estado !== 0) {
    error.value = 'La orden ya está guardada y no admite cambios.'
    return
  }
  const response = await runMutation(
    () => posApi.addProduct(selectedOrder.value.id, { producto_id: product.id, cantidad: 1 }),
    `${product.nombre} agregado.`,
  )
  if (response) await refreshOrders(selectedId.value, true)
}

async function scanBarcode() {
  const code = barcode.value.trim()
  if (!code) return
  const response = await runMutation(() => productsApi.searchBarcode(code))
  if (!response) return
  barcode.value = ''
  await addProduct(normalizeProduct(response.data))
}

async function changeQuantity(line, change) {
  const quantity = line.cantidad + change
  if (quantity < 1) return
  const response = await runMutation(() => posApi.updateProduct(selectedOrder.value.id, line.id, { cantidad: quantity }))
  if (response) await refreshOrders(selectedId.value, true)
}

async function removeLine(line) {
  const response = await runMutation(() => posApi.removeProduct(selectedOrder.value.id, line.id), 'Producto retirado.')
  if (response) await refreshOrders(selectedId.value, true)
}

async function saveOrder() {
  const response = await runMutation(
    () => posApi.saveOrder(selectedOrder.value.id, { extra: extra.value }),
    'Orden guardada y lista para cobrar.',
  )
  if (response) await refreshOrders(selectedId.value)
}

async function deleteOrder() {
  if (!window.confirm(`¿Eliminar la orden ${selectedOrder.value.noOrder}?`)) return
  const response = await runMutation(() => posApi.deleteOrder(selectedOrder.value.id), 'Orden eliminada.')
  if (response) await refreshOrders(null)
}

async function checkLoyalty() {
  const response = await runMutation(() => posApi.loyaltyCheck(selectedOrder.value.telefono))
  if (response) loyalty.value = response.data
}

function updateLoyaltyPoints(value) {
  if (!loyalty.value?.registered) {
    loyaltyPoints.value = 0
    return
  }
  const rate = Math.max(1, Number(loyalty.value.pesos_per_point || 1))
  const maximumByTotal = Math.floor(posOrderTotal(selectedOrder.value, extra.value, 0) * rate)
  const maximum = Math.min(Number(loyalty.value.points || 0), maximumByTotal)
  let points = Math.min(maximum, Math.max(0, Math.floor(Number(value || 0))))
  points -= points % rate
  if (points > 0 && points < Number(loyalty.value.minimum_to_redeem || 0)) points = 0
  loyaltyPoints.value = points
}

function updateExtra(value) {
  extra.value = Math.max(0, Number(value || 0))
  updateLoyaltyPoints(loyaltyPoints.value)
}

async function payOrder() {
  const response = await runMutation(() => posApi.payOrder(selectedOrder.value.id, {
    tipo_pago: paymentMethod.value,
    loyalty_points: Math.max(0, Number(loyaltyPoints.value || 0)),
  }))
  if (!response) return
  receipt.value = normalizePosOrder(response.data)
  notice.value = response.idempotent ? 'Esta orden ya estaba cobrada.' : 'Cobro registrado.'
  selectedId.value = null
  await refreshOrders(null)
  await reloadProducts()
}

async function reloadProducts() {
  try {
    products.value = await fetchCatalog()
  } catch (exception) {
    error.value = exception.message || 'El cobro terminó, pero no fue posible actualizar el catálogo.'
  }
}

function printTicket() {
  window.print()
}

function closeReceipt() {
  receipt.value = null
}

function releaseDialog() {
  document.querySelector('.dashboard-shell')?.removeAttribute('inert')
}
</script>

<template>
  <section class="pos-page">
    <header class="page-heading pos-heading">
      <div><p class="eyebrow">VENTA DIRECTA</p><h1>Punto de venta</h1><p>Captura pedidos, cobra y descuenta inventario en una sola operación.</p></div>
      <RouterLink v-if="auth.hasRole('super-admin') || auth.can('pos.history')" class="button button-secondary" to="/dashboard/pos/history">Ver historial</RouterLink>
    </header>

    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <div v-if="error" class="alert alert-error product-error" role="alert"><span>{{ error }}</span><button type="button" @click="error = ''">Cerrar</button></div>

    <section class="pos-new-order panel">
      <label><span>Cliente</span><input v-model="customerName" type="text" maxlength="255" /></label>
      <label><span>Teléfono para lealtad</span><input v-model="customerPhone" type="tel" maxlength="30" placeholder="Opcional" /></label>
      <button ref="newOrderButton" class="button button-primary" type="button" :disabled="locked" @click="createOrder">+ Nueva orden</button>
    </section>

    <nav class="pos-order-tabs" aria-label="Órdenes activas">
      <button
        v-for="order in orders"
        :key="order.id"
        type="button"
        :class="{ active: order.id === selectedId }"
        :aria-pressed="order.id === selectedId"
        @click="selectOrder(order.id)"
      >
        <strong>{{ order.nombre }}</strong><span>{{ order.noOrder }}</span>
      </button>
      <span v-if="!loading && !orders.length">No hay órdenes activas.</span>
    </nav>

    <div class="pos-workspace">
      <main class="pos-products">
        <form class="pos-tools" @submit.prevent="scanBarcode">
          <label class="pos-search"><span class="sr-only">Buscar producto</span><input v-model="search" type="search" placeholder="Buscar por nombre, categoría o código" /></label>
          <label><span class="sr-only">Categoría</span><select v-model="category"><option value="all">Todas las categorías</option><option v-for="item in categories" :key="item" :value="item">{{ item }}</option></select></label>
          <label class="pos-barcode"><span class="sr-only">Código de barras</span><input v-model="barcode" type="search" inputmode="numeric" placeholder="Escanear código" /></label>
          <button class="button button-secondary" type="submit" :disabled="locked || !barcode.trim()">Agregar</button>
        </form>
        <PosProductCatalog :products="filteredProducts" :loading="productLoading" :busy="locked" @add="addProduct" />
      </main>

      <PosOrderPanel
        :order="selectedOrder"
        :extra="extra"
        :discount="estimatedDiscount"
        :payment-method="paymentMethod"
        :loyalty="loyalty"
        :loyalty-points="loyaltyPoints"
        :busy="locked"
        @update:extra="updateExtra"
        @update:payment-method="paymentMethod = $event"
        @update:loyalty-points="updateLoyaltyPoints"
        @quantity="changeQuantity"
        @remove="removeLine"
        @save="saveOrder"
        @pay="payOrder"
        @delete="deleteOrder"
        @check-loyalty="checkLoyalty"
      />
    </div>

    <Teleport to="body">
      <div v-if="receipt" class="pos-receipt-backdrop" role="dialog" aria-modal="true" aria-label="Ticket de venta" @keydown.esc="closeReceipt">
        <article class="pos-receipt">
          <p class="eyebrow">VENTA COMPLETADA</p><h2>Ticket {{ receipt.noOrder }}</h2>
          <p>{{ receipt.nombre }}<br />{{ receipt.fecha }}</p>
          <ul><li v-for="line in receipt.details" :key="line.id"><span>{{ line.cantidad }} × {{ line.nameProd }}</span><strong>${{ line.precioNeto.toFixed(2) }}</strong></li></ul>
          <dl><div v-if="receipt.extra"><dt>Extra</dt><dd>${{ receipt.extra.toFixed(2) }}</dd></div><div v-if="receipt.descuento"><dt>Descuento</dt><dd>-${{ receipt.descuento.toFixed(2) }}</dd></div><div><dt>Total</dt><dd>${{ receipt.total.toFixed(2) }}</dd></div></dl>
          <footer><button ref="receiptClose" class="button button-secondary" type="button" @click="closeReceipt">Cerrar</button><button class="button button-primary" type="button" @click="printTicket">Imprimir</button></footer>
        </article>
      </div>
    </Teleport>
  </section>
</template>

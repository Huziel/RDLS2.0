<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { publicStoreApi } from '../../../api/public-store.js'
import { ensureCartToken, money } from '../../../utils/public-store.js'

const route = useRoute()

const serial = route.params.serial
const orderRef = String(route.query.order ?? '')
const order = ref(null)
const theme = ref({ colors: null, extra: {} })
const loading = ref(true)
const error = ref('')
const paying = ref(false)
const payError = ref('')
const payLink = ref('')
const cancelled = ref(false)

const colors = computed(() => {
  const c = theme.value?.colors
  return {
    primary: c?.primary || '#667eea',
    dark: c?.dark || '#1e293b',
    light: c?.light || '#f8fafc',
  }
})

const extra = computed(() => theme.value?.extra ?? {})
const hasBankAccount = computed(() => Boolean(extra.value.nombre_propietario1 && extra.value.transferencia1))
const bankAccounts = computed(() => {
  const accounts = []
  if (extra.value.nombre_banco1 || extra.value.transferencia1) {
    accounts.push({
      banco: extra.value.nombre_banco1 || 'Banco',
      propietario: extra.value.nombre_propietario1 || '',
      transferencia: extra.value.transferencia1 || '',
    })
  }
  if (extra.value.nombre_banco2 || extra.value.transferencia2) {
    accounts.push({
      banco: extra.value.nombre_banco2 || 'Banco',
      propietario: extra.value.nombre_propietario2 || '',
      transferencia: extra.value.transferencia2 || '',
    })
  }
  return accounts
})

const qrUrl = computed(() => {
  if (!orderRef) return ''
  const url = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(window.location.href)}`
  return url
})

const statusLabel = computed(() => {
  const status = order.value?.status ?? ''
  const estado = order.value?.estado ?? ''
  const payment = order.value?.payment?.order_state ?? ''
  if (payment === 'paid' || status === 'pagada') return 'Pagada'
  if (estado === 'cancelada' || status === 'cancelada' || order.value?.cancelled_at) return 'Cancelada'
  if (payment === 'pending' || status === 'pendiente' || status === 'en_proceso') return 'Pendiente'
  return status || 'Pendiente'
})

const isPaid = computed(() => statusLabel.value === 'Pagada')
const isCancelled = computed(() => statusLabel.value === 'Cancelada')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [orderRes, themeRes] = await Promise.allSettled([
      publicStoreApi.orderDetail(orderRef),
      publicStoreApi.theme(serial),
    ])
    if (orderRes.status === 'fulfilled') order.value = orderRes.value.data
    if (themeRes.status === 'fulfilled') theme.value = themeRes.value.data
    if (orderRes.status === 'rejected') {
      error.value = orderRes.reason?.message || 'No se encontró el pedido.'
    }
  } finally {
    loading.value = false
  }
}

async function payWithMercadoPago() {
  paying.value = true
  payError.value = ''
  payLink.value = ''
  try {
    const res = await publicStoreApi.payOrder(serial, orderRef)
    const data = res.data ?? {}
    const link = data.init_point || data.sandbox_init_point
    if (link) {
      payLink.value = link
    } else {
      payError.value = data.message || 'La tienda no tiene MercadoPago configurado. Realiza la transferencia bancaria.'
    }
  } catch (e) {
    payError.value = e?.message || 'La tienda no tiene MercadoPago configurado. Realiza la transferencia bancaria.'
  } finally {
    paying.value = false
  }
}

async function verifyPayment() {
  try {
    const res = await publicStoreApi.orderStatus(serial, orderRef)
    const data = res.data ?? {}
    if (data.status === 'paid' || data.order_state === 'paid') {
      await load()
    }
  } catch {
    /* keep the current view */
  }
}

async function cancelOrder() {
  if (!window.confirm('¿Cancelar este pedido? Se reabrirá el inventario.')) return
  cancelled.value = false
  error.value = ''
  try {
    await publicStoreApi.cancelOrder(serial, orderRef)
    cancelled.value = true
    await load()
  } catch (e) {
    error.value = e?.message || 'No fue posible cancelar el pedido.'
  }
}

function onQrError(event) {
  event.target.style.display = 'none'
}

function printView() {
  window.print()
}

onMounted(() => {
  ensureCartToken()
  load()
})
</script>

<template>
  <div class="ps-view" :style="{ background: colors.light }">
    <header class="ps-topbar" :style="{ background: colors.dark }">
      <span class="ps-brand"><i class="fas fa-check-circle" /> Pedido recibido</span>
      <button class="ps-cart-link" type="button" @click="printView"><i class="fas fa-print" /> Imprimir</button>
    </header>

    <main class="ps-body">
      <div v-if="loading" class="ps-loader">
        <span class="ps-spinner" :style="{ color: colors.primary }" />
        <span>Consultando tu pedido...</span>
      </div>

      <template v-else-if="order">
        <div v-if="error" class="ps-alert ps-alert-error" role="alert">{{ error }}</div>
        <div v-if="cancelled" class="ps-alert ps-alert-success" role="status">Pedido cancelado. | <strong>Stock restaurado</strong>.</div>

        <div class="ps-thanks-card" :style="{ borderColor: colors.primary }">
          <span class="ps-thanks-status" :class="{ paid: isPaid, cancelled: isCancelled }">{{ statusLabel }}</span>
          <h1>¡Gracias, {{ order.cliente }}!</h1>
          <p>Recibimos tu pedido <strong>{{ order.order }}</strong>. A continuación verás los pasos para completarlo.</p>

          <ul class="ps-thanks-steps">
            <li>
              <b>1. Realiza tu pago</b>
              <span>Mediante transferencia bancaria con los datos de abajo, o con MercadoPago.</span>
            </li>
            <li>
              <b>2. Confirma tu operación</b>
              <span>Envía tu comprobante por WhatsApp y recibirás confirmación.</span>
            </li>
            <li>
              <b>3. Recibe tu pedido</b>
              <span>Espera la confirmación de entrega o preparación para recoger.</span>
            </li>
          </ul>
        </div>

        <div class="ps-thanks-grid">
          <section class="ps-thanks-panel">
            <h2>Detalle del pedido</h2>
            <dl class="ps-order-lines">
              <div><dt>Cliente</dt><dd>{{ order.cliente }}</dd></div>
              <div><dt>Teléfono</dt><dd>{{ order.telefono }}</dd></div>
              <div><dt>Folio</dt><dd>{{ order.order }}</dd></div>
              <div><dt>Fecha</dt><dd>{{ order.fecha }}</dd></div>
              <div v-if="order.envio"><dt>Envío</dt><dd>{{ money(order.envio) }}</dd></div>
            </dl>

            <ul class="ps-thanks-items">
              <li v-for="item in order.items" :key="item.name + item.price">
                <span>
                  {{ item.qty }}× {{ item.name }}
                  <small v-for="addon in item.addons" :key="addon.name">+ {{ addon.name }}</small>
                </span>
                <b>{{ money(item.price) }}</b>
              </li>
            </ul>

            <div class="ps-thanks-total"><span>Total</span><strong>{{ money(order.total) }}</strong></div>

            <img v-if="qrUrl" :src="qrUrl" alt="Código QR del pedido" class="ps-qr" @error="onQrError" />

            <div class="ps-thanks-actions">
              <button class="ps-button ps-button-ghost" type="button" @click="verifyPayment">Verificar pago</button>
              <button
                v-if="!isPaid && !isCancelled"
                class="ps-button ps-button-ghost"
                type="button"
                @click="cancelOrder"
              >
                Cancelar pedido
              </button>
            </div>
          </section>

          <section v-if="bankAccounts.length" class="ps-thanks-panel">
            <h2>Pago por transferencia</h2>
            <div v-for="account in bankAccounts" :key="account.transferencia" class="ps-bank-account">
              <p><strong>{{ account.banco }}</strong></p>
              <p>Propietario: {{ account.propietario }}</p>
              <p class="ps-account-number">{{ account.transferencia }}</p>
            </div>
            <p class="ps-bank-note">Envíanos tu comprobante por WhatsApp para confirmar tu pedido.</p>
          </section>
        </div>

        <section v-if="payLink" class="ps-thanks-panel ps-mp-panel">
          <h2>Pagar con MercadoPago</h2>
          <p>Tu pedido ya fue creado. Continúa el pago en MercadoPago:</p>
          <a class="ps-button" :href="payLink" target="_blank" rel="noopener">
            <i class="fab fa-mercadopago" /> Ir a pagar
          </a>
        </section>

        <section v-else class="ps-thanks-panel ps-mp-panel">
          <h2>¿Prefieres pagar con MercadoPago?</h2>
          <p v-if="payError" class="ps-alert ps-alert-error">{{ payError }}</p>
          <button class="ps-button ps-button-ghost" type="button" :disabled="paying" @click="payWithMercadoPago">
            {{ paying ? 'Generando pago...' : 'Generar link de pago' }}
          </button>
        </section>
      </template>

      <div v-else-if="error" class="ps-alert ps-alert-error" role="alert">{{ error }}</div>
    </main>
  </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Merriweather&display=swap');

.ps-thanks-card {
  background: #fff;
  border-top: 5px solid;
  border-radius: 16px;
  padding: 28px;
  position: relative;
}

.ps-thanks-status {
  position: absolute;
  top: 18px;
  right: 18px;
  background: #fef3c7;
  color: #b45309;
  font-weight: 800;
  font-size: 0.76rem;
  padding: 5px 12px;
  border-radius: 999px;
  text-transform: uppercase;
}

.ps-thanks-status.paid {
  background: #dcfce7;
  color: #15803d;
}

.ps-thanks-status.cancelled {
  background: #fee2e2;
  color: #b91c1c;
}

.ps-thanks-card h1 {
  margin: 0 0 8px;
  font-size: 1.4rem;
}

.ps-thanks-card p {
  color: #475569;
  margin: 0;
}

.ps-thanks-steps {
  list-style: none;
  margin: 20px 0 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.ps-thanks-steps li {
  background: #f8fafc;
  border-radius: 12px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ps-thanks-steps b {
  display: block;
  color: #0f172a;
}

.ps-thanks-steps span {
  font-size: 0.84rem;
  color: #64748b;
  line-height: 1.45;
}

.ps-thanks-grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 18px;
  margin-top: 18px;
}

.ps-thanks-panel {
  background: #fff;
  border-radius: 16px;
  padding: 20px;
  box-shadow: 0 4px 14px rgba(30, 41, 59, 0.06);
}

.ps-thanks-panel h2 {
  margin: 0 0 14px;
  font-size: 1.02rem;
}

.ps-order-lines {
  margin: 0 0 12px;
}

.ps-order-lines div {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  border-bottom: 1px dashed #e2e8f0;
  font-size: 0.9rem;
}

.ps-order-lines dt {
  color: #64748b;
}

.ps-order-lines dd {
  margin: 0;
  font-weight: 600;
  color: #0f172a;
}

.ps-thanks-items {
  list-style: none;
  margin: 0;
  padding: 0;
}

.ps-thanks-items li {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 0;
  font-size: 0.9rem;
}

.ps-thanks-items small {
  display: block;
  color: #94a3b8;
  font-size: 0.76rem;
}

.ps-thanks-total {
  display: flex;
  justify-content: space-between;
  border-top: 2px solid #e2e8f0;
  margin-top: 8px;
  padding-top: 12px;
  font-size: 1.05rem;
}

.ps-qr {
  display: block;
  margin: 18px auto 0;
  border-radius: 10px;
}

.ps-thanks-actions {
  display: flex;
  gap: 10px;
  margin-top: 16px;
  flex-wrap: wrap;
}

.ps-bank-account {
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px 14px;
  margin-bottom: 10px;
}

.ps-bank-account p {
  margin: 2px 0;
  color: #475569;
  font-size: 0.9rem;
}

.ps-account-number {
  font-family: monospace;
  font-size: 0.95rem;
  letter-spacing: 0.5px;
  color: #0f172a !important;
  font-weight: 700;
}

.ps-bank-note {
  color: #64748b;
  font-size: 0.84rem;
  margin: 10px 0 0;
}

.ps-mp-panel {
  margin-top: 18px;
}

@media (min-width: 720px) {
  .ps-thanks-card h1 {
    font-size: 1.6rem;
  }
}

@media (max-width: 720px) {
  .ps-thanks-steps {
    grid-template-columns: 1fr;
  }

  .ps-thanks-grid {
    grid-template-columns: 1fr;
  }
}

@media print {
  .ps-topbar,
  .ps-thanks-actions,
  .ps-cart-link {
    display: none !important;
  }
}
</style>
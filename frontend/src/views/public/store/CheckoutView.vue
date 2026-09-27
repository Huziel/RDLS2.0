<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { publicStoreApi } from '../../../api/public-store.js'
import {
  ensureCartToken,
  money,
  cartSubtotal,
  checkoutIdempotencyKey,
} from '../../../utils/public-store.js'

const route = useRoute()
const router = useRouter()

const serial = route.params.serial
const theme = ref({ colors: null, features: {}, shipping_costs: {}, payment_methods: {} })
const items = ref([])
const loading = ref(true)
const submitting = ref(false)
const error = ref('')
const cartLoaded = ref(false)
const readinessLoaded = ref(false)

const form = reactive({
  nombre: '',
  telefono: '',
  tipo_envio: 'shipping',
  payment_method: 'cash',
  direccion: '',
  ciudad: '',
  codigo_postal: '',
  lat: 0,
  lng: 0,
})

const colors = computed(() => {
  const c = theme.value?.colors
  return {
    primary: c?.primary || '#667eea',
    dark: c?.dark || '#1e293b',
    light: c?.light || '#f8fafc',
  }
})

const features = computed(() => theme.value?.features ?? {})
const shippingCosts = computed(() => theme.value?.shipping_costs ?? {})
const paymentMethods = computed(() => theme.value?.payment_methods ?? {})

const shippingTypes = computed(() => {
  const list = []
  if (features.value.pickup) list.push({ value: 'pickup', label: 'Retiro en tienda', cost: 0 })
  if (features.value.shipping) list.push({ value: 'shipping', label: 'Envío local', cost: localShippingCost.value })
  if (features.value.national_shipping) list.push({ value: 'national', label: 'Envío nacional', cost: 0 })
  return list
})

const localShippingCost = computed(() => {
  const costs = shippingCosts.value
  const tiers = [Number(costs.base ?? costs.basic ?? 0), Number(costs.medio ?? 0), Number(costs.largo ?? 0)].filter(Number.isFinite)
  return tiers.length ? Math.max(...tiers) : 0
})

// Pago contra entrega solo es válido con envío local; el backend lo rechaza
// con cualquier otro tipo de envío, así que no se ofrece aquí.
const availablePaymentMethods = computed(() => {
  const pm = paymentMethods.value
  const list = []
  if (pm.cash) list.push({ value: 'cash', label: 'Efectivo' })
  if (pm.bank_transfer) list.push({ value: 'bank_transfer', label: 'Transferencia bancaria' })
  if (pm.mercado_pago) list.push({ value: 'mercado_pago', label: 'MercadoPago' })
  if (pm.cash_on_delivery && form.tipo_envio === 'shipping') {
    list.push({ value: 'cash_on_delivery', label: 'Pago contra entrega' })
  }
  return list
})

watch(availablePaymentMethods, (list) => {
  if (!list.some((method) => method.value === form.payment_method)) {
    form.payment_method = list[0]?.value ?? ''
  }
})

watch(shippingTypes, (list) => {
  if (!list.some((type) => type.value === form.tipo_envio)) {
    form.tipo_envio = list.find((type) => type.value === 'shipping')?.value ?? list[0]?.value ?? ''
  }
})

const requiresAddress = computed(() => form.tipo_envio !== 'pickup')
const subtotal = computed(() => cartSubtotal(items.value))
const shipping = computed(() => {
  const type = shippingTypes.value.find((t) => t.value === form.tipo_envio)
  return type?.cost ?? 0
})
const total = computed(() => subtotal.value + shipping.value)
const checkoutReady = computed(() =>
  cartLoaded.value &&
  readinessLoaded.value &&
  items.value.length > 0 &&
  shippingTypes.value.length > 0 &&
  availablePaymentMethods.value.length > 0,
)

onMounted(async () => {
  ensureCartToken()
  try {
    const [cartRes, themeRes] = await Promise.allSettled([
      publicStoreApi.cart(serial),
      publicStoreApi.theme(serial),
    ])
    const lockedOut = [cartRes, themeRes].some(
      (result) => result.status === 'rejected' &&
        (result.reason?.status === 401 || result.reason?.status === 403),
    )
    if (lockedOut) {
      publicStoreApi.clearStoreCapability(serial)
      router.replace(`/store/${serial}`)
      return
    }
    if (cartRes.status === 'fulfilled') {
      items.value = cartRes.value.data?.items ?? []
      cartLoaded.value = true
    } else {
      error.value = cartRes.reason?.message || 'No fue posible cargar el carrito.'
    }
    if (themeRes.status === 'fulfilled') {
      theme.value = themeRes.value.data
      readinessLoaded.value = true
      if (!shippingTypes.value.length || !availablePaymentMethods.value.length) {
        error.value = 'La tienda no tiene envío y pago disponibles para crear el pedido.'
      }
    } else {
      error.value = themeRes.reason?.message || 'No fue posible verificar las formas de envío y pago.'
    }
  } catch (e) {
    error.value = e?.message || 'No fue posible preparar el checkout.'
  } finally {
    loading.value = false
  }
})

async function placeOrder() {
  error.value = ''
  if (!checkoutReady.value) {
    error.value = 'No se puede crear el pedido sin verificar envío y forma de pago.'
    return
  }
  submitting.value = true
  try {
    const payload = {
      nombre: form.nombre.trim(),
      telefono: form.telefono.trim(),
      payment_method: form.payment_method,
      costo_envio: shipping.value,
      tipo_envio: form.tipo_envio,
      direccion: form.direccion.trim(),
      ciudad: form.ciudad.trim(),
      codigo_postal: form.codigo_postal.trim(),
      lat: Number(form.lat) || 0,
      lng: Number(form.lng) || 0,
    }
    const key = checkoutIdempotencyKey(serial, payload, items.value)
    const res = await publicStoreApi.checkout(serial, payload, key)
    const order = res.data?.order_id
    if (!order) {
      throw new Error('El servidor no devolvió el folio del pedido.')
    }
    router.replace({ path: `/store/${serial}/thanks`, query: { order } })
  } catch (e) {
    if (e?.status === 409) {
      error.value =
        'Ya existe un pedido con estos datos. Revisa el folio en "Ver mi pedido" o vacía el carrito para comenzar otro.'
      return
    }
    if (e?.status === 401 || e?.status === 403) {
      publicStoreApi.clearStoreCapability(serial)
      router.replace(`/store/${serial}`)
      return
    }
    error.value = e?.message || 'No fue posible procesar el pedido.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="ps-view" :style="{ background: colors.light }">
    <header class="ps-topbar" :style="{ background: colors.dark }">
      <router-link class="ps-brand" :to="`/store/${serial}/cart`"><i class="fas fa-arrow-left" /> Carrito</router-link>
      <span class="ps-cart-link">Checkout</span>
    </header>

    <main class="ps-body">
      <div v-if="loading" class="ps-loader">
        <span class="ps-spinner" :style="{ color: colors.primary }" />
        <span>Preparando pedido...</span>
      </div>

      <template v-else>
        <div v-if="error" class="ps-alert ps-alert-error" role="alert">{{ error }}</div>

        <div v-if="cartLoaded && !items.length" class="ps-empty">
          <strong>Tu carrito está vacío</strong>
          <router-link class="ps-button" :style="{ background: colors.primary }" :to="`/store/${serial}`">
            Ver productos
          </router-link>
        </div>

        <form v-else-if="items.length" class="ps-checkout-layout" @submit.prevent="placeOrder">
          <section class="ps-form">
            <h2>Datos del pedido</h2>

            <div class="ps-field">
              <label for="co-nombre">Nombre</label>
              <input id="co-nombre" v-model="form.nombre" required autocomplete="name" />
            </div>

            <div class="ps-field">
              <label for="co-telefono">Teléfono / WhatsApp</label>
              <input id="co-telefono" v-model="form.telefono" required autocomplete="tel" />
            </div>

            <fieldset class="ps-shipping" :disabled="submitting">
              <legend>Envío</legend>
              <label v-for="type in shippingTypes" :key="type.value">
                <input v-model="form.tipo_envio" type="radio" name="tipo_envio" :value="type.value" required />
                <span>{{ type.label }}</span>
                <b>{{ money(type.cost) }}</b>
              </label>
            </fieldset>

            <fieldset class="ps-shipping" :disabled="submitting">
              <legend>Forma de pago</legend>
              <label v-for="method in availablePaymentMethods" :key="method.value">
                <input v-model="form.payment_method" type="radio" name="payment_method" :value="method.value" required />
                <span>{{ method.label }}</span>
              </label>
            </fieldset>

            <template v-if="requiresAddress">
              <div class="ps-field">
                <label for="co-direccion">Dirección</label>
                <input id="co-direccion" v-model="form.direccion" required />
              </div>
              <div class="ps-field">
                <label for="co-ciudad">Ciudad</label>
                <input id="co-ciudad" v-model="form.ciudad" required />
              </div>
              <div class="ps-field">
                <label for="co-cp">Código postal</label>
                <input id="co-cp" v-model="form.codigo_postal" required />
              </div>
            </template>

            <div class="ps-field">
              <label for="co-entrega">Fecha de entrega</label>
              <input id="co-entrega" type="date" :min="new Date().toISOString().split('T')[0]" />
            </div>
          </section>

          <aside class="ps-checkout-summary" :style="{ borderColor: colors.dark }">
            <h2>Resumen</h2>
            <ul>
              <li v-for="item in items" :key="item.id">
                <span>{{ item.quantity }}× {{ item.product_name }}</span>
                <b>{{ money(item.price) }}</b>
              </li>
            </ul>
            <dl>
              <div><dt>Subtotal</dt><dd>{{ money(subtotal) }}</dd></div>
              <div><dt>Envío</dt><dd>{{ money(shipping) }}</dd></div>
              <div class="ps-total"><dt>Total</dt><dd>{{ money(total) }}</dd></div>
            </dl>
            <button class="ps-button" :style="{ background: colors.primary }" type="submit" :disabled="submitting || !checkoutReady">
              {{ submitting ? 'Procesando...' : 'Continuar a pago' }}
            </button>
          </aside>
        </form>
      </template>
    </main>
  </div>
</template>

<style scoped>
.ps-checkout-layout {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
  align-items: start;
}

.ps-form {
  background: #fff;
  border-radius: 16px;
  padding: 22px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  box-shadow: 0 4px 14px rgba(30, 41, 59, 0.06);
}

.ps-form h2,
.ps-checkout-summary h2 {
  margin: 0;
  font-size: 1.1rem;
}

.ps-shipping {
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.ps-shipping legend {
  font-weight: 700;
  font-size: 0.82rem;
  color: #334155;
}

.ps-shipping label {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 10px;
  cursor: pointer;
}

.ps-shipping label:has(:checked) {
  background: var(--ps-accent, rgba(102, 126, 234, 0.12));
}

.ps-shipping span {
  flex: 1;
  color: #1e293b;
}

.ps-shipping b {
  color: #334155;
}

.ps-checkout-summary {
  background: #fff;
  border-top: 4px solid;
  border-radius: 14px;
  padding: 18px;
  box-shadow: 0 4px 14px rgba(30, 41, 59, 0.06);
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.ps-checkout-summary ul {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ps-checkout-summary li {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font-size: 0.9rem;
  color: #334155;
}

.ps-checkout-summary dl {
  margin: 0;
}

.ps-checkout-summary dl div {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  color: #334155;
}

.ps-checkout-summary .ps-total {
  border-top: 1px solid #e2e8f0;
  margin-top: 6px;
  padding-top: 12px;
  font-weight: 800;
  color: #0f172a;
}

@media (max-width: 720px) {
  .ps-checkout-layout {
    grid-template-columns: 1fr;
  }
}
</style>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { publicStoreApi } from '../../../api/public-store.js'
import { ensureCartToken, money, cartSubtotal, cartCount } from '../../../utils/public-store.js'

const route = useRoute()
const router = useRouter()

const serial = route.params.serial
const theme = ref({ colors: null })
const items = ref([])
const loading = ref(true)
const error = ref('')
const note = ref('')
const couponCode = ref('')
const applyingCoupon = ref(false)
const updatingId = ref(null)

const colors = computed(() => {
  const c = theme.value?.colors
  return {
    primary: c?.primary || '#667eea',
    dark: c?.dark || '#1e293b',
    light: c?.light || '#f8fafc',
  }
})

const subtotal = computed(() => cartSubtotal(items.value))
const count = computed(() => cartCount(items.value))
const discount = computed(() => items.value.reduce((sum, i) => sum + Number(i.discount ?? 0), 0))
const total = computed(() => Math.max(0, subtotal.value - discount.value))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [cartRes, themeRes] = await Promise.allSettled([
      publicStoreApi.cart(serial),
      publicStoreApi.theme(serial),
    ])
    if (cartRes.status === 'fulfilled') items.value = cartRes.value.data?.items ?? []
    if (themeRes.status === 'fulfilled') theme.value = themeRes.value.data
  } catch (e) {
    error.value = e?.message || 'No fue posible cargar el carrito.'
  } finally {
    loading.value = false
  }
}

async function changeQuantity(item, delta) {
  const next = Number(item.quantity) + delta
  if (next < 1) return
  updatingId.value = item.id
  error.value = ''
  try {
    await publicStoreApi.updateCartItem(serial, item.id, next)
    await load()
  } catch (e) {
    error.value = e?.message || 'No fue posible actualizar la cantidad.'
  } finally {
    updatingId.value = null
  }
}

async function removeItem(item) {
  updatingId.value = item.id
  error.value = ''
  try {
    await publicStoreApi.removeCartItem(serial, item.id)
    await load()
  } catch (e) {
    error.value = e?.message || 'No fue posible eliminar el producto.'
  } finally {
    updatingId.value = null
  }
}

async function applyCoupon() {
  if (!couponCode.value.trim()) return
  applyingCoupon.value = true
  error.value = ''
  note.value = ''
  try {
    const res = await publicStoreApi.applyCoupon(serial, couponCode.value)
    note.value = res?.message || 'Cupón aplicado.'
    await load()
  } catch (e) {
    error.value = e?.message || 'Cupón inválido.'
  } finally {
    applyingCoupon.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="ps-view" :style="{ background: colors.light }">
    <header class="ps-topbar" :style="{ background: colors.dark }">
      <router-link class="ps-brand" :to="`/store/${serial}`"><i class="fas fa-arrow-left" /> Volver</router-link>
      <span class="ps-cart-link">Carrito</span>
    </header>

    <main class="ps-body">
      <div v-if="loading" class="ps-loader">
        <span class="ps-spinner" :style="{ color: colors.primary }" />
        <span>Cargando carrito...</span>
      </div>

      <template v-else>
        <div v-if="error" class="ps-alert ps-alert-error" role="alert">{{ error }}</div>
        <div v-if="note" class="ps-alert ps-alert-success" role="status">{{ note }}</div>

        <div v-if="!items.length && !error" class="ps-empty">
          <strong>Tu carrito está vacío</strong>
          <p>Explora el catálogo y agrega productos.</p>
          <router-link class="ps-button" :style="{ background: colors.primary }" :to="`/store/${serial}`">
            Ver productos
          </router-link>
        </div>

        <div v-else-if="items.length" class="ps-cart-layout">
          <section class="ps-cart-list">
            <article v-for="item in items" :key="item.id" class="ps-cart-item">
              <img v-if="item.product_image" :src="item.product_image" :alt="item.product_name" />
              <div v-else class="ps-cart-noimg" />
              <div class="ps-cart-info">
                <h3>{{ item.product_name }}</h3>
                <span v-for="addon in item.addons" :key="addon.id" class="ps-cart-addon">+ {{ addon.name }}</span>
                <strong>{{ money(item.price) }}</strong>
              </div>
              <div class="ps-cart-actions">
                <div class="ps-qty-controls">
                  <button type="button" :disabled="updatingId === item.id" @click="changeQuantity(item, -1)">−</button>
                  <span>{{ item.quantity }}</span>
                  <button type="button" :disabled="updatingId === item.id" @click="changeQuantity(item, 1)">+</button>
                </div>
                <button class="ps-cart-remove" type="button" @click="removeItem(item)" aria-label="Quitar">×</button>
              </div>
            </article>
          </section>

          <aside class="ps-cart-summary" :style="{ borderColor: colors.dark }">
            <h2>Resumen</h2>
            <div class="ps-coupon-row">
              <input v-model="couponCode" placeholder="Cupón" @keyup.enter="applyCoupon" />
              <button type="button" :disabled="applyingCoupon" @click="applyCoupon">Aplicar</button>
            </div>
            <dl>
              <div><dt>Subtotal</dt><dd>{{ money(subtotal) }}</dd></div>
              <div v-if="discount"><dt>Descuento</dt><dd>−{{ money(discount) }}</dd></div>
              <div class="ps-total"><dt>Total</dt><dd>{{ money(total) }}</dd></div>
            </dl>
            <button class="ps-button" :style="{ background: colors.primary }" type="button" @click="router.push(`/store/${serial}/checkout`)">
              Ir a pagar
            </button>
            <small>{{ count }} producto(s) en el carrito</small>
          </aside>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
.ps-cart-layout {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 22px;
  align-items: start;
}

.ps-cart-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ps-cart-item {
  display: flex;
  gap: 14px;
  padding: 14px;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 4px 14px rgba(30, 41, 59, 0.06);
}

.ps-cart-item img,
.ps-cart-noimg {
  width: 84px;
  height: 84px;
  border-radius: 10px;
  object-fit: cover;
  background: #f1f5f9;
  flex-shrink: 0;
}

.ps-cart-info {
  flex: 1;
  min-width: 0;
}

.ps-cart-info h3 {
  margin: 0 0 4px;
  font-size: 0.96rem;
  color: #0f172a;
}

.ps-cart-addon {
  display: inline-block;
  margin: 2px 6px 4px 0;
  font-size: 0.76rem;
  color: #64748b;
}

.ps-cart-info strong {
  display: block;
  margin-top: 4px;
  color: #0f172a;
}

.ps-cart-actions {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
}

.ps-qty-controls {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 3px;
}

.ps-qty-controls button {
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: 50%;
  background: #e2e8f0;
  cursor: pointer;
}

.ps-qty-controls span {
  min-width: 22px;
  text-align: center;
  font-weight: 700;
}

.ps-cart-remove {
  border: 0;
  background: transparent;
  color: #94a3b8;
  font-size: 1.2rem;
  cursor: pointer;
}

.ps-cart-summary {
  background: #fff;
  border-top: 4px solid;
  border-radius: 14px;
  padding: 18px;
  box-shadow: 0 4px 14px rgba(30, 41, 59, 0.06);
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.ps-cart-summary h2 {
  margin: 0;
  font-size: 1.1rem;
}

.ps-coupon-row {
  display: flex;
  gap: 8px;
}

.ps-coupon-row input {
  flex: 1;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  padding: 9px 12px;
}

.ps-coupon-row button {
  border: 0;
  background: #0f172a;
  color: #fff;
  border-radius: 10px;
  padding: 9px 12px;
  cursor: pointer;
}

.ps-cart-summary dl {
  margin: 0;
}

.ps-cart-summary dl div {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  color: #334155;
}

.ps-cart-summary dt {
  font-weight: 600;
}

.ps-cart-summary .ps-total {
  border-top: 1px solid #e2e8f0;
  margin-top: 6px;
  padding-top: 12px;
  font-size: 1.05rem;
  color: #0f172a;
}

.ps-cart-summary small {
  color: #94a3b8;
  text-align: center;
}

@media (max-width: 640px) {
  .ps-cart-layout {
    grid-template-columns: 1fr;
  }
}
</style>
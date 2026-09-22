<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { publicStoreApi } from '../../../api/public-store.js'
import { ensureCartToken, money, selectableStock } from '../../../utils/public-store.js'

const route = useRoute()
const router = useRouter()

const serial = route.params.serial
const productId = route.params.productId

const product = ref(null)
const theme = ref({ colors: null })
const loading = ref(true)
const error = ref('')
const quantity = ref(1)
const selectedAddons = ref([])
const submitting = ref(false)
const success = ref('')

const colors = computed(() => {
  const c = theme.value?.colors
  return {
    primary: c?.primary || '#667eea',
    dark: c?.dark || '#1e293b',
    light: c?.light || '#f8fafc',
  }
})

const stock = computed(() => selectableStock(product.value?.stock))
const outOfStock = computed(() => stock.value <= 0)

const addonsTotal = computed(() =>
  (product.value?.aditivos ?? []).reduce((sum, addon) => {
    if (selectedAddons.value.includes(addon.id)) sum += Number(addon.precio)
    return sum
  }, 0),
)

const unitPrice = computed(() => Number(product.value?.precio ?? 0) + addonsTotal.value)
const total = computed(() => unitPrice.value * quantity.value)

const toggleAddon = (id) => {
  selectedAddons.value = selectedAddons.value.includes(id)
    ? selectedAddons.value.filter((x) => x !== id)
    : [...selectedAddons.value, id]
}

onMounted(async () => {
  ensureCartToken()
  try {
    const [productRes, themeRes] = await Promise.allSettled([
      publicStoreApi.product(productId),
      publicStoreApi.theme(serial),
    ])
    if (productRes.status === 'fulfilled') product.value = productRes.value.data
    if (themeRes.status === 'fulfilled') theme.value = themeRes.value.data
    if (productRes.status === 'rejected') {
      error.value = productRes.reason?.message || 'Producto no disponible.'
    }
  } finally {
    loading.value = false
  }
})

async function addToCart() {
  if (outOfStock.value || submitting.value) return
  submitting.value = true
  success.value = ''
  try {
    await publicStoreApi.addToCart(serial, {
      product_id: product.value.id,
      quantity: quantity.value,
      addon_ids: selectedAddons.value,
    })
    success.value = `${quantity.value} agregado(s) al carrito.`
    window.setTimeout(() => router.push(`/store/${serial}/cart`), 650)
  } catch (e) {
    error.value = e?.message || 'Error al agregar el producto.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="ps-view" :style="{ background: colors.light }">
    <header class="ps-topbar" :style="{ background: colors.dark }">
      <router-link class="ps-brand" :to="`/store/${serial}`">
        <i class="fas fa-arrow-left" /> Volver
      </router-link>
      <router-link class="ps-cart-link" :to="`/store/${serial}/cart`">Carrito</router-link>
    </header>

    <main class="ps-body">
      <div v-if="loading" class="ps-loader">
        <span class="ps-spinner" :style="{ color: colors.primary }" />
        <span>Cargando producto...</span>
      </div>

      <template v-else-if="product">
        <div v-if="error" class="ps-alert ps-alert-error" role="alert">{{ error }}</div>
        <div v-if="success" class="ps-alert ps-alert-success" role="status">{{ success }}</div>

        <div class="ps-detail">
          <div class="ps-detail-gallery">
            <img
              v-if="product.imagen"
              :src="product.imagen"
              :alt="product.nombre"
              class="ps-detail-main"
            />
            <div v-else class="ps-detail-fallback">Sin imagen</div>
          </div>

          <section class="ps-detail-info">
            <p class="ps-detail-category">{{ product.categoria }}</p>
            <h1>{{ product.nombre }}</h1>
            <p v-if="product.descripcion" class="ps-detail-desc">{{ product.descripcion }}</p>

            <div class="ps-detail-price">
              <strong>{{ money(unitPrice) }}</strong>
              <span v-if="outOfStock" class="ps-out-badge">Agotado</span>
            </div>

            <div v-if="product.aditivos?.length" class="ps-detail-addons">
              <h3>Extras</h3>
              <label v-for="addon in product.aditivos" :key="addon.id" class="ps-addon-row">
                <input
                  type="checkbox"
                  :value="addon.id"
                  :checked="selectedAddons.includes(addon.id)"
                  :disabled="outOfStock"
                  @change="toggleAddon(addon.id)"
                />
                <span>{{ addon.nombre }}</span>
                <b>+{{ money(addon.precio) }}</b>
              </label>
            </div>

            <div v-if="!outOfStock" class="ps-qty-row">
              <span>Cantidad</span>
              <div class="ps-qty-controls">
                <button type="button" :disabled="quantity <= 1" @click="quantity -= 1">−</button>
                <span>{{ quantity }}</span>
                <button type="button" @click="quantity += 1">+</button>
              </div>
            </div>

            <button
              class="ps-button ps-detail-cta"
              :style="{ background: colors.primary }"
              type="button"
              :disabled="outOfStock || submitting"
              @click="addToCart"
            >
              {{ outOfStock ? 'Agotado' : `Agregar · ${money(total)}` }}
            </button>
          </section>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
.ps-detail {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 28px;
  align-items: start;
}

.ps-detail-gallery {
  border-radius: 16px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 6px 22px rgba(30, 41, 59, 0.1);
}

.ps-detail-main {
  width: 100%;
  aspect-ratio: 1 / 1;
  object-fit: cover;
  display: block;
}

.ps-detail-fallback {
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
}

.ps-detail-info h1 {
  margin: 6px 0 10px;
  color: #0f172a;
  font-size: 1.5rem;
}

.ps-detail-category {
  display: inline-block;
  margin: 0;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(100, 116, 139, 0.12);
  color: #475569;
  font-size: 0.76rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}

.ps-detail-desc {
  color: #475569;
  line-height: 1.6;
}

.ps-detail-price {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 14px 0;
}

.ps-detail-price strong {
  font-size: 1.7rem;
  color: #0f172a;
}

.ps-out-badge {
  background: #fee2e2;
  color: #b91c1c;
  padding: 4px 10px;
  border-radius: 999px;
  font-weight: 700;
  font-size: 0.78rem;
}

.ps-detail-addons h3 {
  margin: 0 0 8px;
  font-size: 0.95rem;
  color: #334155;
}

.ps-addon-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  margin-bottom: 8px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  cursor: pointer;
}

.ps-addon-row span {
  flex: 1;
  color: #1e293b;
  font-size: 0.92rem;
}

.ps-addon-row b {
  color: #334155;
}

.ps-qty-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 16px 0;
  color: #334155;
  font-weight: 600;
}

.ps-qty-controls {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 4px;
}

.ps-qty-controls button {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 0;
  background: #f1f5f9;
  font-size: 1rem;
  cursor: pointer;
}

.ps-qty-controls span {
  min-width: 24px;
  text-align: center;
  font-weight: 700;
}

.ps-detail-cta {
  width: 100%;
  padding: 14px;
  font-size: 1rem;
}

@media (max-width: 720px) {
  .ps-detail {
    grid-template-columns: 1fr;
  }
}
</style>
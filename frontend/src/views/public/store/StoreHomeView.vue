<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { publicStoreApi } from '../../../api/public-store.js'
import { ensureCartToken, money, cartCount } from '../../../utils/public-store.js'

const route = useRoute()
const router = useRouter()

const serial = route.params.serial
const theme = ref({ colors: null })
const store = ref(null)
const products = ref([])
const categories = ref([])
const activeCategory = ref('')
const cartItems = ref([])
const loading = ref(true)
const error = ref('')
const passwordGate = ref(false)
const password = ref('')
const passwordError = ref('')
const addingId = ref(null)
const added = ref('')
const cartVisible = ref(false)

const colors = computed(() => {
  const c = theme.value?.colors
  return {
    primary: c?.primary || '#667eea',
    dark: c?.dark || '#1e293b',
    light: c?.light || '#f8fafc',
  }
})

const storeName = computed(() => theme.value?.extra?.nombre_tienda || store.value?.extra?.nombre_tienda || serial)

const cartQty = computed(() => cartCount(cartItems.value))

const visibleProducts = computed(() => {
  if (!activeCategory.value) return products.value
  return products.value.filter((p) => p.categoria === activeCategory.value)
})

const unlocked = () => sessionStorage.getItem(`ps-unlocked:${serial}`) === '1'

onMounted(async () => {
  ensureCartToken()

  if (!unlocked()) {
    try {
      const themeRes = await publicStoreApi.theme(serial)
      theme.value = themeRes.data
      passwordGate.value = Boolean(themeRes.data?.has_password)
    } catch {
      /* theme is cosmetic; the catalog still renders */
    }
  }
  try {
    const storeRes = await publicStoreApi.store(serial)
    store.value = storeRes.data
  } catch {
    /* store metadata is cosmetic */
  }

  if (passwordGate.value && !unlocked()) {
    loading.value = false
    return
  }

  await loadCatalog()
})

async function loadCatalog() {
  loading.value = true
  error.value = ''
  try {
    const [productsRes, cartRes] = await Promise.allSettled([
      publicStoreApi.products(serial, { per_page: 200 }),
      publicStoreApi.cart(serial),
    ])
    if (productsRes.status === 'fulfilled') {
      products.value = productsRes.value.data ?? []
      categories.value = [...new Set(products.value.map((p) => p.categoria).filter(Boolean))]
    }
    if (cartRes.status === 'fulfilled') cartItems.value = cartRes.value.data?.items ?? []
  } catch (e) {
    error.value = e?.message || 'No fue posible cargar el catálogo.'
  } finally {
    loading.value = false
  }
}

async function submitPassword() {
  passwordError.value = ''
  try {
    const storeId = store.value?.id ?? serial
    await publicStoreApi.verifyPassword(storeId, password.value)
    sessionStorage.setItem(`ps-unlocked:${serial}`, '1')
    passwordGate.value = false
    await loadCatalog()
  } catch (e) {
    passwordError.value = e?.message || 'Contraseña incorrecta.'
  }
}

async function addToCart(product) {
  addingId.value = product.id
  added.value = ''
  try {
    await publicStoreApi.addToCart(serial, { product_id: product.id, quantity: 1, addon_ids: [] })
    added.value = `${product.nombre} agregado al carrito.`
    const res = await publicStoreApi.cart(serial)
    cartItems.value = res.data?.items ?? []
    cartVisible.value = true
    window.setTimeout(() => (cartVisible.value = false), 2200)
  } catch (e) {
    error.value = e?.message || 'Error al agregar el producto.'
  } finally {
    addingId.value = null
  }
}

function goProduct(product) {
  router.push(`/store/${serial}/product/${product.id}`)
}
</script>

<template>
  <div class="ps-view" :style="{ background: colors.light }">
    <header class="ps-topbar" :style="{ background: colors.dark }">
      <router-link class="ps-brand" :to="`/store/${serial}`">
        <img v-if="store?.logo" :src="store.logo" alt="" />
        <span>{{ storeName }}</span>
      </router-link>
      <router-link class="ps-cart-link" :to="`/store/${serial}/cart`" aria-label="Carrito">
        <i class="fas fa-shopping-bag" aria-hidden="true" />
        Carrito
        <span v-if="cartQty" class="ps-cart-badge">{{ cartQty }}</span>
      </router-link>
    </header>

    <main class="ps-body">
      <div v-if="passwordGate" class="ps-password-card">
        <h1 :style="{ color: colors.dark }">Catálogo protegido</h1>
        <p>Esta tienda protege su catálogo con contraseña.</p>
        <div class="ps-field">
          <label for="catalog-password">Contraseña</label>
          <input id="catalog-password" v-model="password" type="password" @keyup.enter="submitPassword" />
        </div>
        <div v-if="passwordError" class="ps-alert ps-alert-error" role="alert">{{ passwordError }}</div>
        <button class="ps-button" :style="{ background: colors.primary }" type="button" @click="submitPassword">
          Ver catálogo
        </button>
      </div>

      <template v-else>
        <div v-if="loading" class="ps-loader">
          <span class="ps-spinner" :style="{ color: colors.primary }" />
          <span>Cargando catálogo...</span>
        </div>

        <template v-else>
          <div v-if="error" class="ps-alert ps-alert-error" role="alert">{{ error }}</div>
          <div v-if="added" class="ps-alert ps-alert-success" role="status">{{ added }}</div>

          <section v-if="categories.length" class="ps-categories" aria-label="Categorías">
            <button
              :class="{ active: activeCategory === '' }"
              :style="activeCategory === '' ? { background: colors.primary } : {}"
              type="button"
              @click="activeCategory = ''"
            >Todo</button>
            <button
              v-for="category in categories"
              :key="category"
              :class="{ active: activeCategory === category }"
              :style="activeCategory === category ? { background: colors.primary } : {}"
              type="button"
              @click="activeCategory = category"
            >
              {{ category }}
            </button>
          </section>

          <div v-if="!visibleProducts.length && !error" class="ps-empty">
            <strong>Catálogo vacío</strong>
            <p>El vendedor aún no publica productos.</p>
          </div>

          <section v-else class="ps-grid">
            <article v-for="product in visibleProducts" :key="product.id" class="ps-card">
              <button class="ps-card-media" type="button" :aria-label="`Ver ${product.nombre}`" @click="goProduct(product)">
                <img v-if="product.imagen" :src="product.imagen" alt="" loading="lazy" />
                <span v-else class="ps-card-fallback">Sin imagen</span>
              </button>
              <div class="ps-card-info">
                <h3 @click="goProduct(product)">{{ product.nombre }}</h3>
                <p v-if="product.descripcion">{{ product.descripcion }}</p>
                <strong>{{ money(product.precio) }}</strong>
                <button
                  class="ps-button"
                  :style="{ background: colors.primary }"
                  type="button"
                  :disabled="Number(product.stock) <= 0 || addingId === product.id"
                  @click="addToCart(product)"
                >
                  {{ Number(product.stock) <= 0 ? 'Agotado' : 'Agregar' }}
                </button>
              </div>
            </article>
          </section>
        </template>
      </template>
    </main>

    <Transition name="ps-toast">
      <div v-if="cartVisible" class="ps-toast" :style="{ background: colors.dark }">
        <i class="fas fa-check-circle" /> {{
          added || 'Producto en el carrito'
        }}
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.ps-password-card {
  max-width: 380px;
  margin: 60px auto;
  padding: 28px;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 10px 30px rgba(30, 41, 59, 0.12);
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.ps-password-card h1 {
  font-size: 1.3rem;
  margin: 0;
}

.ps-password-card p {
  color: #64748b;
  margin: 0 0 4px;
}

.ps-categories {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 24px;
}

.ps-categories button {
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  border-radius: 999px;
  padding: 8px 16px;
  font-size: 0.86rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.ps-categories button.active {
  border-color: transparent;
  color: #fff;
}

.ps-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 18px;
}

.ps-card {
  border-radius: 16px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 4px 16px rgba(30, 41, 59, 0.08);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.ps-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 24px rgba(30, 41, 59, 0.14);
}

.ps-card-media {
  display: block;
  width: 100%;
  aspect-ratio: 1 / 1;
  border: 0;
  padding: 0;
  background: #f1f5f9;
  cursor: pointer;
}

.ps-card-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.ps-card-fallback {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
  font-size: 0.85rem;
}

.ps-card-info {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.ps-card-info h3 {
  margin: 0;
  font-size: 0.98rem;
  color: #0f172a;
  cursor: pointer;
}

.ps-card-info p {
  margin: 0;
  color: #64748b;
  font-size: 0.82rem;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.ps-card-info strong {
  color: var(--ps-accent, #0f172a);
  font-size: 1.02rem;
}

.ps-toast {
  position: fixed;
  left: 50%;
  bottom: 24px;
  transform: translateX(-50%);
  color: #fff;
  padding: 12px 18px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9rem;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
}

.ps-toast-enter-active,
.ps-toast-leave-active {
  transition: all 0.25s ease;
}

.ps-toast-enter-from,
.ps-toast-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(10px);
}

@media (max-width: 640px) {
  .ps-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
  }
}
</style>
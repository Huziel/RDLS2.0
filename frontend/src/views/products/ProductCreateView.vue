<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import ProductForm from '../../components/products/ProductForm.vue'
import { productsApi } from '../../api/products.js'
import { storeApi } from '../../api/store.js'
import { getApiErrorMessage, getFieldErrors } from '../../utils/api-error.js'
import { emptyProductForm } from '../../utils/products.js'

const router = useRouter()
const categories = ref([])
const saving = ref(false)
const error = ref('')
const serverErrors = ref({})
const limitLoading = ref(true)
const limitError = ref('')
const maxProducts = ref(null)
const currentProducts = ref(0)
const initialValue = emptyProductForm()

const limitReached = computed(() => maxProducts.value !== null && currentProducts.value >= maxProducts.value)

async function checkLimit() {
  limitLoading.value = true
  limitError.value = ''
  const subscriptionResult = await Promise.allSettled([storeApi.getSubscription()])
  const [subscriptionResponse] = subscriptionResult
  if (subscriptionResponse.status === 'rejected') {
    limitError.value = 'No fue posible verificar el límite de productos de tu plan.'
  } else {
    const limit = subscriptionResponse.value.data?.max_products
    maxProducts.value = limit == null ? null : Number(limit)
    currentProducts.value = Number(subscriptionResponse.value.data?.current_products || 0)
  }
  limitLoading.value = false
}

onMounted(async () => {
  const categoryResult = await Promise.allSettled([productsApi.categories()])
  const [categoriesResponse] = categoryResult
  if (categoriesResponse.status === 'fulfilled') categories.value = categoriesResponse.value.data || []
  await checkLimit()
})

async function save(payload) {
  if (limitReached.value) return
  saving.value = true
  error.value = ''
  serverErrors.value = {}
  try {
    await productsApi.create(payload)
    await router.replace({ path: '/dashboard/products', query: { created: '1' } })
  } catch (requestError) {
    error.value = getApiErrorMessage(requestError, 'No fue posible crear el producto.')
    serverErrors.value = getFieldErrors(requestError)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="products-page product-form-page">
    <header class="page-heading"><div><p class="eyebrow">CATÁLOGO</p><h1>Nuevo producto</h1><p>Registra la información utilizada por el catálogo y el punto de venta.</p></div></header>
    <div v-if="limitLoading" class="inline-state">Verificando los límites del plan...</div>
    <div v-else-if="limitError" class="product-limit" role="alert">
      <strong>{{ limitError }}</strong>
      <p>La creación permanece bloqueada para evitar exceder el plan por error.</p>
      <button class="button button-secondary" type="button" @click="checkLimit">Reintentar</button>
    </div>
    <div v-else-if="limitReached" class="product-limit" role="alert">
      <strong>Alcanzaste el límite de {{ maxProducts }} productos de tu plan.</strong>
      <p>Elimina un producto existente o cambia de plan antes de registrar otro.</p>
      <RouterLink class="button button-secondary" to="/dashboard/products">Volver a productos</RouterLink>
    </div>
    <ProductForm
      v-else
      :initial-value="initialValue"
      :categories="categories"
      :saving="saving"
      :api-error="error"
      :server-errors="serverErrors"
      submit-label="Crear producto"
      @submit="save"
      @cancel="router.push('/dashboard/products')"
    />
  </section>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ProductAddonsEditor from '../../components/products/ProductAddonsEditor.vue'
import ProductForm from '../../components/products/ProductForm.vue'
import { productsApi } from '../../api/products.js'
import { getApiErrorMessage, getFieldErrors } from '../../utils/api-error.js'
import { productToForm } from '../../utils/products.js'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const product = ref(null)
const initialValue = ref(null)
const categories = ref([])
const error = ref('')
const loadStatus = ref(null)
const serverErrors = ref({})
const loadedProductId = ref(null)
let loadRequest = 0

async function load() {
  const productId = String(route.params.id)
  const currentRequest = ++loadRequest
  loading.value = true
  product.value = null
  initialValue.value = null
  loadedProductId.value = null
  error.value = ''
  serverErrors.value = {}
  loadStatus.value = null
  const [productResult, categoryResult] = await Promise.allSettled([
    productsApi.get(productId),
    productsApi.categories(),
  ])

  if (currentRequest !== loadRequest) return
  if (categoryResult.status === 'fulfilled') categories.value = categoryResult.value.data || []
  if (productResult.status === 'fulfilled') {
    product.value = productResult.value.data
    initialValue.value = productToForm(product.value)
    loadedProductId.value = productId
  } else {
    loadStatus.value = productResult.reason?.status || null
    error.value = getApiErrorMessage(productResult.reason, 'No fue posible cargar el producto.')
  }
  loading.value = false
}

async function save(payload) {
  if (!loadedProductId.value) return
  saving.value = true
  error.value = ''
  serverErrors.value = {}
  try {
    await productsApi.update(loadedProductId.value, payload)
    await router.replace({ path: '/dashboard/products', query: { updated: '1' } })
  } catch (requestError) {
    error.value = getApiErrorMessage(requestError, 'No fue posible actualizar el producto.')
    serverErrors.value = getFieldErrors(requestError)
  } finally {
    saving.value = false
  }
}

watch(() => route.params.id, load, { immediate: true })
</script>

<template>
  <section class="products-page product-form-page">
    <header class="page-heading"><div><p class="eyebrow">CATÁLOGO</p><h1>Editar producto</h1><p>Actualiza los datos del producto y administra sus extras.</p></div></header>
    <div v-if="loading" class="product-loading"><span class="product-spinner" /> Cargando producto...</div>
    <div v-else-if="!product" class="product-empty product-load-error">
      <strong>{{ loadStatus === 404 ? 'Producto no encontrado' : 'No se pudo abrir el producto' }}</strong>
      <p>{{ error }}</p>
      <div class="product-actions">
        <button class="button button-primary" type="button" @click="load">Reintentar</button>
        <RouterLink class="button button-secondary" to="/dashboard/products">Volver</RouterLink>
      </div>
    </div>
    <template v-else>
      <ProductForm
        :initial-value="initialValue"
        :categories="categories"
        :saving="saving"
        :api-error="error"
        :server-errors="serverErrors"
        submit-label="Actualizar producto"
        @submit="save"
        @cancel="router.push('/dashboard/products')"
      />
      <ProductAddonsEditor :key="loadedProductId" :product-id="loadedProductId" />
    </template>
  </section>
</template>

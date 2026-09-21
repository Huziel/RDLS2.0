<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth.js'
import ProductFilters from '../../components/products/ProductFilters.vue'
import ProductPagination from '../../components/products/ProductPagination.vue'
import ProductTable from '../../components/products/ProductTable.vue'
import { useProductList } from '../../composables/useProductList.js'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const success = ref('')
const {
  products,
  categories,
  meta,
  loading,
  error,
  deletingId,
  filters,
  load,
  loadCategories,
  remove,
} = useProductList()

let filtersReady = false
watch(
  () => [filters.search, filters.category, filters.active],
  () => {
    if (filtersReady) load(1)
  },
)

onMounted(async () => {
  if (route.query.created) success.value = 'Producto creado exitosamente.'
  if (route.query.updated) success.value = 'Producto actualizado.'
  await Promise.all([load(), loadCategories()])
  filtersReady = true
})

async function confirmDelete(product) {
  if (!window.confirm(`¿Eliminar "${product.nombre}"?`)) return
  if (await remove(product)) success.value = 'Producto eliminado.'
}

function newProduct() {
  router.push('/dashboard/products/create')
}
</script>

<template>
  <section class="products-page">
    <header class="page-heading products-heading">
      <div><p class="eyebrow">CATÁLOGO</p><h1>Productos</h1><p>Administra los artículos disponibles en tus canales de venta.</p></div>
      <button v-if="auth.hasRole('super-admin') || auth.can('products.create')" class="button button-primary" type="button" @click="newProduct">+ Nuevo producto</button>
    </header>

    <div v-if="success" class="alert alert-success" role="status">{{ success }}</div>
    <div v-if="error" class="alert alert-error product-error" role="alert">
      <span>{{ error }}</span><button type="button" @click="load(meta.current_page)">Reintentar</button>
    </div>

    <ProductFilters
      v-model:search="filters.search"
      v-model:category="filters.category"
      v-model:active="filters.active"
      :categories="categories"
    />

    <div v-if="loading" class="product-loading" aria-live="polite"><span class="product-spinner" /> Cargando productos...</div>
    <div v-else-if="!error && !products.length" class="product-empty">
      <strong>No hay productos para mostrar</strong>
      <p>Ajusta los filtros o registra el primer producto del catálogo.</p>
      <button v-if="auth.hasRole('super-admin') || auth.can('products.create')" class="button button-primary" type="button" @click="newProduct">Nuevo producto</button>
    </div>
    <ProductTable
      v-else-if="products.length"
      :products="products"
      :deleting-id="deletingId"
      :can-edit="auth.hasRole('super-admin') || auth.can('products.update')"
      :can-delete="auth.hasRole('super-admin') || auth.can('products.delete')"
      @delete="confirmDelete"
    />
    <ProductPagination :meta="meta" :disabled="loading" @page="load" />
  </section>
</template>

<script setup>
import { formatProductPrice } from '../../utils/products.js'

defineProps({
  products: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
})

defineEmits(['add'])
</script>

<template>
  <section class="pos-catalog panel">
    <div v-if="loading" class="page-state">Cargando productos...</div>
    <div v-else-if="!products.length" class="empty-state">No hay productos disponibles.</div>
    <div v-else class="pos-product-grid">
      <button
        v-for="product in products"
        :key="product.id"
        class="pos-product-card"
        :disabled="busy || product.stock <= 0"
        type="button"
        @click="$emit('add', product)"
      >
        <img v-if="product.imagen" :src="product.imagen" alt="" />
        <span v-else class="pos-product-placeholder" aria-hidden="true">{{ product.nombre.slice(0, 1).toUpperCase() }}</span>
        <span class="pos-product-copy">
          <strong>{{ product.nombre }}</strong>
          <small>{{ product.categoria || 'Sin categoría' }}</small>
          <b>{{ formatProductPrice(product.precio) }}</b>
          <em :class="{ empty: product.stock <= 0, low: product.stock > 0 && product.stock < 5 }">
            {{ product.stock <= 0 ? 'Agotado' : `${product.stock} disponibles` }}
          </em>
        </span>
      </button>
    </div>
  </section>
</template>

<script setup>
import { formatProductPrice } from '../../utils/products.js'

defineProps({
  products: { type: Array, required: true },
  deletingId: { type: Number, default: null },
  canEdit: { type: Boolean, default: true },
  canDelete: { type: Boolean, default: true },
})

defineEmits(['delete'])
</script>

<template>
  <div class="product-results">
    <table class="product-table">
      <thead>
        <tr><th>Imagen</th><th>Nombre</th><th>Precio</th><th>Categoría</th><th>Stock</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <tr v-for="product in products" :key="product.id">
          <td>
            <img v-if="product.imagen" class="product-thumb" :src="product.imagen" :alt="product.nombre" />
            <span v-else class="product-no-image">Sin imagen</span>
          </td>
          <td><strong>{{ product.nombre }}</strong></td>
          <td>{{ formatProductPrice(product.precio) }}</td>
          <td>{{ product.categoria || 'Sin categoría' }}</td>
          <td>{{ product.stock }}</td>
          <td><span class="status-badge" :class="product.activo ? 'active' : 'inactive'">{{ product.activo ? 'Activo' : 'Inactivo' }}</span></td>
          <td class="product-actions">
            <RouterLink v-if="canEdit" class="button button-secondary button-small" :aria-label="`Editar ${product.nombre}`" :to="`/dashboard/products/${product.id}/edit`">Editar</RouterLink>
            <button v-if="canDelete" class="button button-danger button-small" type="button" :aria-label="`Eliminar ${product.nombre}`" :disabled="deletingId !== null" @click="$emit('delete', product)">
              {{ deletingId === product.id ? 'Eliminando...' : 'Eliminar' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="product-card-list">
      <article v-for="product in products" :key="product.id" class="product-card">
        <img v-if="product.imagen" :src="product.imagen" :alt="product.nombre" />
        <div v-else class="product-card-placeholder">Sin imagen</div>
        <div class="product-card-body">
          <div class="product-card-heading">
            <strong>{{ product.nombre }}</strong>
            <span class="status-badge" :class="product.activo ? 'active' : 'inactive'">{{ product.activo ? 'Activo' : 'Inactivo' }}</span>
          </div>
          <b>{{ formatProductPrice(product.precio) }}</b>
          <span>{{ product.categoria || 'Sin categoría' }}</span>
          <span>Stock: {{ product.stock }}</span>
          <div class="product-actions">
            <RouterLink v-if="canEdit" class="button button-secondary button-small" :aria-label="`Editar ${product.nombre}`" :to="`/dashboard/products/${product.id}/edit`">Editar</RouterLink>
            <button v-if="canDelete" class="button button-danger button-small" type="button" :aria-label="`Eliminar ${product.nombre}`" :disabled="deletingId !== null" @click="$emit('delete', product)">Eliminar</button>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>

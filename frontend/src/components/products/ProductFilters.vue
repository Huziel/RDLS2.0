<script setup>
defineProps({
  search: { type: String, default: '' },
  category: { type: String, default: 'all' },
  active: { type: String, default: '' },
  categories: { type: Array, default: () => [] },
  disabled: Boolean,
})

const emit = defineEmits(['update:search', 'update:category', 'update:active', 'change'])

function update(field, value) {
  emit(`update:${field}`, value)
  emit('change')
}
</script>

<template>
  <div class="product-filters" aria-label="Filtros de productos">
    <label class="filter-search">
      <span class="sr-only">Buscar producto</span>
      <input
        :value="search"
        type="search"
        placeholder="Buscar producto..."
        :disabled="disabled"
        @input="update('search', $event.target.value)"
      />
    </label>

    <label>
      <span class="sr-only">Categoría</span>
      <select :value="category" :disabled="disabled" @change="update('category', $event.target.value)">
        <option value="all">Todas las categorías</option>
        <option v-for="item in categories" :key="item" :value="item">{{ item }}</option>
      </select>
    </label>

    <label>
      <span class="sr-only">Estado</span>
      <select :value="active" :disabled="disabled" @change="update('active', $event.target.value)">
        <option value="">Todos</option>
        <option value="1">Activos</option>
        <option value="0">Inactivos</option>
      </select>
    </label>
  </div>
</template>

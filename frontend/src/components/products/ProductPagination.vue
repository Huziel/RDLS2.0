<script setup>
defineProps({
  meta: { type: Object, required: true },
  disabled: Boolean,
})

defineEmits(['page'])
</script>

<template>
  <nav v-if="meta.last_page > 1" class="product-pagination" aria-label="Paginación de productos">
    <button
      type="button"
      :disabled="disabled || meta.current_page <= 1"
      @click="$emit('page', meta.current_page - 1)"
    >
      Anterior
    </button>
    <span>Página {{ meta.current_page }} de {{ meta.last_page }}</span>
    <button
      type="button"
      :disabled="disabled || meta.current_page >= meta.last_page"
      @click="$emit('page', meta.current_page + 1)"
    >
      Siguiente
    </button>
  </nav>
</template>

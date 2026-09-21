<script setup>
import { ref } from 'vue'
import { productsApi } from '../../api/products.js'
import { getApiErrorMessage } from '../../utils/api-error.js'
import { validateProductImage } from '../../utils/products.js'

defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: 'Imagen principal' },
})

const emit = defineEmits(['update:modelValue', 'uploading'])
const uploading = ref(false)
const error = ref('')

async function upload(event) {
  const [file] = event.target.files || []
  event.target.value = ''
  if (!file) return

  error.value = validateProductImage(file)
  if (error.value) return

  uploading.value = true
  emit('uploading', true)
  try {
    const response = await productsApi.uploadImage(file)
    emit('update:modelValue', response.data.url)
  } catch (requestError) {
    error.value = getApiErrorMessage(requestError, 'No fue posible subir la imagen.')
  } finally {
    uploading.value = false
    emit('uploading', false)
  }
}
</script>

<template>
  <div class="product-image-field">
    <label for="product-primary-image-url">{{ label }}</label>
    <div class="image-input-row">
      <input id="product-primary-image-url" :value="modelValue" type="url" placeholder="https://..." @input="$emit('update:modelValue', $event.target.value)" />
      <label class="button button-secondary button-small upload-button">
        {{ uploading ? 'Subiendo...' : 'Subir imagen' }}
        <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" :disabled="uploading" @change="upload" />
      </label>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div v-if="modelValue" class="image-preview">
      <img :src="modelValue" alt="Vista previa" />
      <button type="button" aria-label="Quitar imagen" @click="$emit('update:modelValue', '')">Quitar</button>
    </div>
  </div>
</template>

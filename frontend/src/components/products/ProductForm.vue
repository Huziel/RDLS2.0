<script setup>
import { reactive, ref, watch } from 'vue'
import ProductImageField from './ProductImageField.vue'
import { emptyProductForm, toProductPayload, validateProductForm } from '../../utils/products.js'

const props = defineProps({
  initialValue: { type: Object, default: () => emptyProductForm() },
  categories: { type: Array, default: () => [] },
  saving: Boolean,
  apiError: { type: String, default: '' },
  serverErrors: { type: Object, default: () => ({}) },
  submitLabel: { type: String, default: 'Guardar producto' },
})

const emit = defineEmits(['submit', 'cancel'])
const form = reactive(emptyProductForm())
const errors = ref({})
const additionalImage = ref('')
const imageUploading = ref(false)

watch(
  () => props.initialValue,
  (value) => Object.assign(form, emptyProductForm(), value, { imagenes: [...(value.imagenes || [])] }),
  { immediate: true, deep: true },
)

function addImage() {
  const url = additionalImage.value.trim()
  if (!url || form.imagenes.includes(url)) return
  form.imagenes.push(url)
  additionalImage.value = ''
}

function removeImage(index) {
  form.imagenes.splice(index, 1)
}

function fieldError(field) {
  return errors.value[field] || props.serverErrors[field]?.[0] || ''
}

function submit() {
  if (imageUploading.value) return
  errors.value = validateProductForm(form)
  if (Object.keys(errors.value).length) return
  emit('submit', toProductPayload(form))
}
</script>

<template>
  <form class="product-editor" novalidate @submit.prevent="submit">
    <div v-if="apiError" class="alert alert-error" role="alert">{{ apiError }}</div>

    <section class="product-section">
      <div class="section-heading">
        <div><h2>Información del producto</h2><p>Datos visibles en el catálogo, POS y tienda pública.</p></div>
      </div>

      <div class="product-form-grid">
        <label class="product-field">
          <span>Nombre *</span>
          <input v-model="form.nombre" maxlength="255" autocomplete="off" :aria-invalid="Boolean(fieldError('nombre'))" />
          <small v-if="fieldError('nombre')" class="field-error">{{ fieldError('nombre') }}</small>
        </label>

        <label class="product-field">
          <span>Precio *</span>
          <input v-model="form.precio" type="number" min="0" step="0.01" inputmode="decimal" :aria-invalid="Boolean(fieldError('precio'))" />
          <small v-if="fieldError('precio')" class="field-error">{{ fieldError('precio') }}</small>
        </label>

        <label class="product-field">
          <span>Categoría</span>
          <input v-model="form.categoria" list="product-categories" autocomplete="off" />
          <datalist id="product-categories"><option v-for="category in categories" :key="category" :value="category" /></datalist>
          <small v-if="fieldError('categoria')" class="field-error">{{ fieldError('categoria') }}</small>
        </label>

        <label class="product-field">
          <span>Variable</span>
          <input v-model="form.variable" autocomplete="off" />
          <small v-if="fieldError('variable')" class="field-error">{{ fieldError('variable') }}</small>
        </label>

        <label class="product-field">
          <span>Existencias</span>
          <input v-model="form.stock" type="number" min="0" step="1" inputmode="numeric" :aria-invalid="Boolean(fieldError('stock'))" />
          <small v-if="fieldError('stock')" class="field-error">{{ fieldError('stock') }}</small>
        </label>

        <label class="product-field">
          <span>Código de barras</span>
          <input v-model="form.codigo_barras" inputmode="numeric" autocomplete="off" />
          <small v-if="fieldError('codigo_barras')" class="field-error">{{ fieldError('codigo_barras') }}</small>
        </label>

        <label class="product-field product-field-full">
          <span>Descripción</span>
          <textarea v-model="form.descripcion" rows="5" />
          <small v-if="fieldError('descripcion')" class="field-error">{{ fieldError('descripcion') }}</small>
        </label>

        <label class="product-active product-field-full">
          <input v-model="form.activo" type="checkbox" />
          <span>Producto activo y visible en el catálogo</span>
        </label>
      </div>
    </section>

    <section class="product-section">
      <div class="section-heading"><div><h2>Imágenes</h2><p>Puedes escribir una URL o subir un archivo de hasta 10 MB.</p></div></div>
      <ProductImageField v-model="form.imagen" @uploading="imageUploading = $event" />
      <small v-if="fieldError('imagen')" class="field-error">{{ fieldError('imagen') }}</small>

      <div class="additional-images">
        <label>Imágenes adicionales</label>
        <div class="image-input-row">
          <input v-model="additionalImage" type="url" placeholder="https://..." @keydown.enter.prevent="addImage" />
          <button class="button button-secondary button-small" type="button" @click="addImage">Agregar</button>
        </div>
        <div v-if="form.imagenes.length" class="additional-image-list">
          <figure v-for="(image, index) in form.imagenes" :key="`${image}-${index}`">
            <img :src="image" alt="Imagen adicional" />
            <button type="button" :aria-label="`Quitar imagen ${index + 1}`" @click="removeImage(index)">×</button>
          </figure>
        </div>
        <small v-if="fieldError('imagenes')" class="field-error">{{ fieldError('imagenes') }}</small>
      </div>
    </section>

    <div class="product-form-actions">
      <button class="button button-primary" type="submit" :disabled="saving || imageUploading">{{ imageUploading ? 'Subiendo imagen...' : saving ? 'Guardando...' : submitLabel }}</button>
      <button class="button button-secondary" type="button" :disabled="saving" @click="$emit('cancel')">Cancelar</button>
    </div>
  </form>
</template>

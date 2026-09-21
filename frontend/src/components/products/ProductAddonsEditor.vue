<script setup>
import { reactive, ref, watch } from 'vue'
import { productsApi } from '../../api/products.js'
import { getApiErrorMessage } from '../../utils/api-error.js'
import { formatProductPrice } from '../../utils/products.js'

const props = defineProps({ productId: { type: [Number, String], required: true } })
const addons = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')
const form = reactive({ id: null, nombre: '', precio: '', categoria: '', descripcion: '', activo: true, stock: 0 })
let loadRequest = 0

function reset() {
  Object.assign(form, { id: null, nombre: '', precio: '', categoria: '', descripcion: '', activo: true, stock: 0 })
}

async function load() {
  const productId = props.productId
  const currentRequest = ++loadRequest
  loading.value = true
  addons.value = []
  error.value = ''
  success.value = ''
  reset()
  try {
    const response = await productsApi.addons(productId)
    if (currentRequest !== loadRequest) return
    addons.value = response.data || []
  } catch (requestError) {
    if (currentRequest !== loadRequest) return
    error.value = getApiErrorMessage(requestError, 'No fue posible cargar los extras.')
  } finally {
    if (currentRequest === loadRequest) loading.value = false
  }
}

function edit(addon) {
  Object.assign(form, {
    id: addon.id,
    nombre: addon.nombre,
    precio: addon.precio,
    categoria: addon.categoria || '',
    descripcion: addon.descripcion || '',
    activo: addon.activo,
    stock: addon.stock ?? 0,
  })
}

async function save() {
  error.value = ''
  success.value = ''
  if (!form.nombre.trim()) {
    error.value = 'El nombre del extra es obligatorio.'
    return
  }
  if (form.precio === '' || !Number.isFinite(Number(form.precio)) || Number(form.precio) < 0) {
    error.value = 'El precio del extra debe ser mayor o igual a cero.'
    return
  }
  if (!Number.isInteger(Number(form.stock)) || Number(form.stock) < 0) {
    error.value = 'Las existencias del extra deben ser un entero mayor o igual a cero.'
    return
  }

  saving.value = true
  try {
    const payload = {
      nombre: form.nombre.trim(),
      precio: Number(form.precio),
      categoria: form.categoria.trim() || null,
      descripcion: form.descripcion.trim() || null,
      activo: Boolean(form.activo),
      stock: Number(form.stock),
    }
    if (form.id) await productsApi.updateAddon(props.productId, form.id, payload)
    else await productsApi.createAddon(props.productId, payload)
    success.value = form.id ? 'Extra actualizado.' : 'Extra agregado.'
    reset()
    await load()
  } catch (requestError) {
    error.value = getApiErrorMessage(requestError, 'No fue posible guardar el extra.')
  } finally {
    saving.value = false
  }
}

async function remove(addon) {
  if (!window.confirm(`¿Eliminar el extra "${addon.nombre}"?`)) return
  try {
    await productsApi.removeAddon(props.productId, addon.id)
    success.value = 'Extra eliminado.'
    await load()
  } catch (requestError) {
    error.value = getApiErrorMessage(requestError, 'No fue posible eliminar el extra.')
  }
}

watch(() => props.productId, load, { immediate: true })
</script>

<template>
  <section class="product-section addon-editor">
    <div class="section-heading"><div><h2>Extras o aditivos</h2><p>Opciones adicionales que el cliente puede agregar al producto.</p></div></div>
    <div v-if="success" class="alert alert-success" role="status">{{ success }}</div>
    <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
    <div v-if="loading" class="inline-state">Cargando extras...</div>
    <div v-else-if="addons.length" class="addon-list">
      <article v-for="addon in addons" :key="addon.id">
        <div><strong>{{ addon.nombre }}</strong><span>{{ formatProductPrice(addon.precio) }}</span></div>
        <div class="product-actions">
          <button class="button button-secondary button-small" type="button" @click="edit(addon)">Editar</button>
          <button class="button button-danger button-small" type="button" @click="remove(addon)">Eliminar</button>
        </div>
      </article>
    </div>
    <p v-else class="inline-state">Este producto todavía no tiene extras.</p>

    <div class="addon-form">
      <label><span>Nombre del extra</span><input v-model="form.nombre" maxlength="255" placeholder="Ej. Tamaño grande" /></label>
      <label><span>Precio adicional</span><input v-model="form.precio" type="number" min="0" step="0.01" placeholder="0.00" /></label>
      <label><span>Categoría</span><input v-model="form.categoria" /></label>
      <label><span>Existencias</span><input v-model="form.stock" type="number" min="0" step="1" /></label>
      <label class="addon-description"><span>Descripción</span><input v-model="form.descripcion" /></label>
      <label class="product-active addon-active"><input v-model="form.activo" type="checkbox" /><span>Extra activo</span></label>
      <div class="addon-buttons addon-actions">
        <button class="button button-primary" type="button" :disabled="saving" @click="save">{{ saving ? 'Guardando...' : form.id ? 'Actualizar extra' : 'Agregar extra' }}</button>
        <button v-if="form.id" class="button button-secondary" type="button" @click="reset">Cancelar</button>
      </div>
    </div>
  </section>
</template>

export const MAX_PRODUCT_IMAGE_BYTES = 10 * 1024 * 1024
export const PRODUCT_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']

export function emptyProductForm() {
  return {
    nombre: '',
    precio: '',
    imagen: '',
    descripcion: '',
    variable: '',
    categoria: '',
    activo: true,
    stock: 0,
    codigo_barras: '',
    imagenes: [],
  }
}

export function normalizeProduct(product = {}) {
  const stock = typeof product.stock === 'object'
    ? product.stock?.cantidad
    : product.stock

  return {
    ...product,
    id: Number(product.id),
    precio: Number(product.precio || 0),
    stock: Number(stock || 0),
    activo: Boolean(product.activo),
    imagenes: Array.isArray(product.imagenes) ? product.imagenes : [],
    aditivos: Array.isArray(product.aditivos) ? product.aditivos : [],
  }
}

export function productToForm(product = {}) {
  const normalized = normalizeProduct(product)
  return {
    nombre: normalized.nombre || '',
    precio: product.precio ?? '',
    imagen: normalized.imagen || '',
    descripcion: normalized.descripcion || '',
    variable: normalized.variable || '',
    categoria: normalized.categoria || '',
    activo: normalized.activo,
    stock: normalized.stock,
    codigo_barras: product.codigo_barras == null ? '' : String(product.codigo_barras),
    imagenes: [...normalized.imagenes],
  }
}

function nullableText(value) {
  const text = String(value ?? '').trim()
  return text || null
}

export function toProductPayload(form) {
  return {
    nombre: String(form.nombre ?? '').trim(),
    precio: Number(form.precio),
    imagen: nullableText(form.imagen),
    descripcion: nullableText(form.descripcion),
    variable: nullableText(form.variable),
    categoria: nullableText(form.categoria),
    activo: Boolean(form.activo),
    stock: form.stock === '' || form.stock == null ? null : Number(form.stock),
    codigo_barras: nullableText(form.codigo_barras),
    imagenes: (form.imagenes || []).map((image) => String(image).trim()).filter(Boolean),
  }
}

export function validateProductForm(form) {
  const errors = {}
  const name = String(form.nombre ?? '').trim()
  const price = Number(form.precio)
  const stock = form.stock === '' || form.stock == null ? null : Number(form.stock)

  if (!name) errors.nombre = 'El nombre es obligatorio.'
  else if (name.length > 255) errors.nombre = 'El nombre no puede superar 255 caracteres.'

  if (form.precio === '' || form.precio == null || !Number.isFinite(price)) {
    errors.precio = 'El precio es obligatorio.'
  } else if (price < 0) {
    errors.precio = 'El precio no puede ser negativo.'
  }

  if (stock != null && (!Number.isInteger(stock) || stock < 0)) {
    errors.stock = 'Las existencias deben ser un número entero mayor o igual a cero.'
  }

  return errors
}

export function validateProductImage(file) {
  if (!PRODUCT_IMAGE_TYPES.includes(file.type)) {
    return 'Usa una imagen JPEG, PNG, GIF o WebP.'
  }
  if (file.size > MAX_PRODUCT_IMAGE_BYTES) {
    return 'La imagen no puede superar 10 MB.'
  }
  return ''
}

export function productListParams(filters, page = 1) {
  const params = {
    page,
    per_page: 20,
    search: filters.search || '',
    category: filters.category || 'all',
  }
  if (filters.active !== '') params.active = filters.active
  return params
}

export function formatProductPrice(value) {
  return new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
  }).format(Number(value || 0))
}

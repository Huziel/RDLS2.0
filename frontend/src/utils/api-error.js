export function getApiErrorMessage(error, fallback = 'Ocurrió un error inesperado.') {
  if (typeof error === 'string') return error
  if (error?.message) return error.message

  const firstField = Object.values(error?.errors || {})[0]
  if (Array.isArray(firstField) && firstField[0]) return firstField[0]

  return fallback
}

export function getFieldErrors(error) {
  return error?.errors && typeof error.errors === 'object' ? error.errors : {}
}

import { describe, expect, it } from 'vitest'
import { getApiErrorMessage, getFieldErrors } from '../../src/utils/api-error.js'

describe('API error helpers', () => {
  it('prefers the API message', () => {
    expect(getApiErrorMessage({ message: 'Credenciales incorrectas.' })).toBe('Credenciales incorrectas.')
  })

  it('falls back to the first validation error', () => {
    expect(getApiErrorMessage({ errors: { email: ['El correo es obligatorio.'] } })).toBe('El correo es obligatorio.')
  })

  it('returns an empty field map for malformed errors', () => {
    expect(getFieldErrors(null)).toEqual({})
  })
})

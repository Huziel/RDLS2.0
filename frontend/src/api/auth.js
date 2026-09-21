import { api } from './client.js'

export const authApi = {
  login: (credentials) => api.post('/auth/login', credentials),
  logout: () => api.post('/auth/logout'),
  currentUser: () => api.get('/user'),
}

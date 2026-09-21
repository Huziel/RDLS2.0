import { api } from './client.js'

export const getDashboardStats = () => api.get('/dashboard/stats')

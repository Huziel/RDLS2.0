import { api } from './client.js'

export const getPublicSiteSettings = () => api.get('/public/site-settings')

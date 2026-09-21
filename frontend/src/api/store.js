import { api } from './client.js'

export const storeApi = {
  get: () => api.get('/store'),
  getExtra: () => api.get('/store/extra'),
  getColors: () => api.get('/store/colors'),
  getSubscription: () => api.get('/my-subscription'),
}

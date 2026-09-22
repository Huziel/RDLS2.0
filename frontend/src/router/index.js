import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'
import { createAuthGuard } from './guards.js'

const moduleView = () => import('../views/ModulePendingView.vue')

const dashboardChildren = [
  { path: '', name: 'dashboard', component: () => import('../views/dashboard/DashboardHomeView.vue'), meta: { permission: 'dashboard.view' } },
  { path: 'products', name: 'products', component: () => import('../views/products/ProductListView.vue'), meta: { title: 'Productos', permission: 'products.read' } },
  { path: 'products/create', name: 'product-create', component: () => import('../views/products/ProductCreateView.vue'), meta: { title: 'Nuevo producto', permission: 'products.create' } },
  { path: 'products/:id/edit', name: 'product-edit', component: () => import('../views/products/ProductEditView.vue'), meta: { title: 'Editar producto', permissions: ['products.read', 'products.update'] } },
  { path: 'orders', name: 'orders', component: moduleView, meta: { title: 'Pedidos' } },
  { path: 'coupons', name: 'coupons', component: moduleView, meta: { title: 'Cupones' } },
  { path: 'pos', name: 'pos', component: () => import('../views/pos/PosView.vue'), meta: { title: 'Punto de venta', permission: 'pos.use' } },
  { path: 'pos/history', name: 'pos-history', component: () => import('../views/pos/PosHistoryView.vue'), meta: { title: 'Historial POS', permission: 'pos.history' } },
  { path: 'appointments', name: 'appointments', component: moduleView, meta: { title: 'Agenda' } },
  { path: 'barters', name: 'barters', component: moduleView, meta: { title: 'Trueques' } },
  { path: 'crm', name: 'crm', component: moduleView, meta: { title: 'CRM' } },
  { path: 'crm/:id', name: 'crm-detail', component: moduleView, meta: { title: 'Detalle del cliente' } },
  { path: 'qr', name: 'qr', component: moduleView, meta: { title: 'Códigos QR' } },
  { path: 'analytics', name: 'analytics', component: moduleView, meta: { title: 'Analíticas' } },
  { path: 'media', name: 'media', component: moduleView, meta: { title: 'Multimedia' } },
  { path: 'settings', name: 'store-settings', component: moduleView, meta: { title: 'Configuración' } },
  { path: 'builder', name: 'store-builder', component: moduleView, meta: { title: 'Constructor visual' } },
  { path: 'integrations', name: 'integrations', component: moduleView, meta: { title: 'Integraciones' } },
  { path: 'delivery', name: 'delivery-manage', component: moduleView, meta: { title: 'Delivery' } },
  { path: 'ad-generator', name: 'ad-generator', component: moduleView, meta: { title: 'Generador de anuncios' } },
  { path: 'loyalty', name: 'loyalty', component: moduleView, meta: { title: 'Lealtad y apartados' } },
  { path: 'chat', name: 'chat', component: moduleView, meta: { title: 'Chat' } },
  { path: 'admin-users', name: 'admin-users', component: moduleView, meta: { title: 'Usuarios', roles: ['super-admin'] } },
  { path: 'admin-site-settings', name: 'admin-site-settings', component: moduleView, meta: { title: 'Ajustes del sitio', roles: ['super-admin'] } },
  { path: 'subscriptions', name: 'subscriptions', component: moduleView, meta: { title: 'Suscripciones', roles: ['super-admin'] } },
]

const routes = [
  { path: '/', name: 'landing', component: () => import('../views/LandingView.vue') },
  {
    path: '/',
    component: () => import('../layouts/AuthLayout.vue'),
    children: [
      { path: 'login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
      { path: 'register', name: 'register', component: moduleView, meta: { guest: true, title: 'Registro' } },
      { path: 'forgot-password', name: 'forgot-password', component: moduleView, meta: { guest: true, title: 'Recuperar contraseña' } },
    ],
  },
  {
    path: '/dashboard',
    component: () => import('../layouts/DashboardLayout.vue'),
    meta: { requiresAuth: true, userType: '1' },
    children: dashboardChildren,
  },
  { path: '/onboarding', name: 'onboarding', component: moduleView, meta: { requiresAuth: true, title: 'Configuración inicial' } },
  { path: '/customer', name: 'customer', component: moduleView, meta: { requiresAuth: true, userType: '4', title: 'Mis pedidos' } },
  { path: '/delivery', name: 'delivery', component: moduleView, meta: { requiresAuth: true, userType: '3', title: 'Pedidos disponibles' } },
  { path: '/delivery/profile', name: 'delivery-profile', component: moduleView, meta: { requiresAuth: true, userType: '3', title: 'Perfil de repartidor' } },
  { path: '/delivery/stores', name: 'delivery-stores', component: moduleView, meta: { requiresAuth: true, userType: '3', title: 'Tiendas vinculadas' } },
  { path: '/store/:serial', name: 'public-store', component: () => import('../views/public/store/StoreHomeView.vue'), meta: { title: 'Tienda pública' } },
  { path: '/store/:serial/product/:productId', name: 'public-product', component: () => import('../views/public/store/ProductDetailView.vue'), meta: { title: 'Producto' } },
  { path: '/store/:serial/cart', name: 'public-cart', component: () => import('../views/public/store/CartView.vue'), meta: { title: 'Carrito' } },
  { path: '/store/:serial/checkout', name: 'public-checkout', component: () => import('../views/public/store/CheckoutView.vue'), meta: { title: 'Finalizar compra' } },
  { path: '/store/:serial/thanks', name: 'public-thanks', component: () => import('../views/public/store/ThankYouView.vue'), meta: { title: 'Pedido recibido' } },
  { path: '/marketplace', name: 'marketplace', component: moduleView, meta: { title: 'Marketplace' } },
  { path: '/marketplace/stores', name: 'marketplace-stores', component: moduleView, meta: { title: 'Tiendas' } },
  { path: '/marketplace/product/:id', name: 'marketplace-product', component: moduleView, meta: { title: 'Producto del marketplace' } },
  { path: '/marketplace/store/:id', name: 'marketplace-store', component: moduleView, meta: { title: 'Perfil de tienda' } },
  { path: '/book/:serial', name: 'public-booking', component: moduleView, meta: { title: 'Reservar cita' } },
  { path: '/p/:slug', name: 'custom-page', component: moduleView, meta: { title: 'Página' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach((to) => createAuthGuard(useAuthStore())(to))

export default router

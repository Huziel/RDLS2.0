export function homeForUserType(type) {
  if (String(type) === '1') return '/dashboard'
  if (String(type) === '3') return '/delivery'
  if (String(type) === '4') return '/customer'
  return '/login'
}

export function createAuthGuard(auth, storage = localStorage) {
  return async (to) => {
    await auth.bootstrap()

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
      return { path: '/login', query: { redirect: to.fullPath } }
    }

    if (to.meta.guest && auth.isAuthenticated) {
      return homeForUserType(auth.userType)
    }

    if (to.meta.userType && String(auth.userType) !== String(to.meta.userType)) {
      return homeForUserType(auth.userType)
    }

    if (to.meta.roles?.length && !to.meta.roles.some((role) => auth.hasRole(role))) {
      return homeForUserType(auth.userType)
    }

    const requiredPermissions = [
      ...(to.meta.permissions || []),
      ...(to.meta.permission ? [to.meta.permission] : []),
    ]
    if (requiredPermissions.length
      && !auth.hasRole('super-admin')
      && requiredPermissions.some((permission) => !auth.can(permission))) {
      const home = homeForUserType(auth.userType)
      if (home !== to.path) return home
      if (auth.can('products.read')) return '/dashboard/products'
      if (auth.can('pos.use')) return '/dashboard/pos'
      if (auth.can('pos.history')) return '/dashboard/pos/history'
      return '/'
    }

    const needsOnboarding = auth.isAuthenticated
      && String(auth.userType) === '1'
      && to.path.startsWith('/dashboard')
      && !storage.getItem('onboarding_done')

    if (needsOnboarding) return '/onboarding'
    return true
  }
}

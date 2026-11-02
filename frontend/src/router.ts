import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    /** Open to everyone (the login page) */
    public?: boolean
    /** Admins and super admins only */
    admin?: boolean
    title?: string
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { public: true } },
    { path: '/change-password', name: 'change-password', component: () => import('@/pages/ChangePasswordPage.vue') },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
        { path: 'users', name: 'users', component: () => import('@/pages/admin/UsersPage.vue'), meta: { admin: true, title: 'Members & users' } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (!auth.loaded) {
    try {
      await auth.load()
    } catch {
      // The API is unreachable; the login page shows the error when they try
    }
  }

  if (to.meta.public) {
    return auth.user ? { name: 'dashboard' } : true
  }
  if (!auth.user) {
    return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
  }
  // A temporary password must be replaced before anything else
  if (auth.user.must_change_password) {
    return to.name === 'change-password' ? true : { name: 'change-password' }
  }
  if (to.meta.admin && !auth.isAdmin) {
    return { name: 'dashboard' }
  }
  return true
})

export default router

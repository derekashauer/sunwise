import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/plants',
    name: 'plants',
    component: () => import('@/views/PlantsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/plants/add',
    name: 'add-plant',
    component: () => import('@/views/AddPlantView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/plants/:id',
    name: 'plant-detail',
    component: () => import('@/views/PlantDetailView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/plants/:id/edit',
    name: 'edit-plant',
    component: () => import('@/views/AddPlantView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/sitter',
    name: 'sitter-setup',
    component: () => import('@/views/SitterSetupView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/sitter/:token',
    name: 'sitter-guest',
    component: () => import('@/views/SitterGuestView.vue'),
    meta: { requiresAuth: false, isGuest: true }
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/views/SettingsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/verify/:token',
    name: 'verify',
    component: () => import('@/views/VerifyView.vue'),
    meta: { requiresAuth: false }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach(async (to, from, next) => {
  const auth = useAuthStore()

  // Initialize auth state from localStorage
  if (!auth.initialized) {
    await auth.initialize()
  }

  // Guest routes (sitter mode) don't need auth check
  if (to.meta.isGuest) {
    return next()
  }

  // Protected routes
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next({ name: 'login', query: { redirect: to.fullPath } })
  }

  // Already logged in, redirect away from auth pages
  if (!to.meta.requiresAuth && auth.isAuthenticated && ['login', 'register'].includes(to.name)) {
    return next({ name: 'dashboard' })
  }

  next()
})

export default router

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'

// API GET responses the service worker keeps for offline use. They hold the
// signed-in user's plants and tasks, so they are cleared on logout.
const API_CACHE_NAME = 'sunwise-api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!token.value)

  const api = useApi()

  async function initialize() {
    const storedToken = localStorage.getItem('token')
    const storedUser = localStorage.getItem('user')

    if (storedToken && storedUser) {
      token.value = storedToken
      try {
        user.value = JSON.parse(storedUser)
      } catch (e) {
        user.value = null
      }

      // Check the token in the background so the first screen isn't held up
      // by the network.
      refreshUser()
    }

    initialized.value = true
  }

  // Only a real 401 means the token is bad. No signal, a timeout or a server
  // error keeps the user signed in so the app still opens in a dead spot.
  async function refreshUser() {
    try {
      const response = await api.get('/auth/me', { skipAuthRedirect: true, timeout: 10000 })
      if (response.user) {
        user.value = response.user
        localStorage.setItem('user', JSON.stringify(response.user))
      }
    } catch (error) {
      if (error.status === 401 && token.value) {
        await logout()
        const { default: router } = await import('@/router')
        const current = router.currentRoute.value
        if (current.meta?.requiresAuth) {
          router.replace({ name: 'login', query: { redirect: current.fullPath } })
        }
      }
    }
  }

  async function register(email, password) {
    const response = await api.post('/auth/register', { email, password })
    setAuth(response.token, response.user)
    return response
  }

  async function login(email, password) {
    const response = await api.post('/auth/login', { email, password })
    setAuth(response.token, response.user)
    return response
  }

  async function requestMagicLink(email) {
    return await api.post('/auth/magic-link', { email })
  }

  async function verifyMagicLink(magicToken) {
    const response = await api.get(`/auth/verify/${magicToken}`)
    setAuth(response.token, response.user)
    return response
  }

  function setAuth(newToken, newUser) {
    token.value = newToken
    user.value = newUser
    localStorage.setItem('token', newToken)
    localStorage.setItem('user', JSON.stringify(newUser))
  }

  async function logout() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
    localStorage.removeItem('user')

    // Drop cached API data and any queued offline actions so the next person
    // to sign in on this device doesn't see them. The service worker itself
    // stays registered so the app keeps working offline.
    try {
      if ('caches' in window) {
        await caches.delete(API_CACHE_NAME)
      }
      const { clearOutbox } = await import('@/services/outbox')
      await clearOutbox()
    } catch (e) {
      console.error('Failed to clear offline data:', e)
    }
  }

  return {
    user,
    token,
    initialized,
    isAuthenticated,
    initialize,
    register,
    login,
    requestMagicLink,
    verifyMagicLink,
    logout
  }
})

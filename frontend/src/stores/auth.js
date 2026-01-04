import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'

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
      user.value = JSON.parse(storedUser)

      // Verify token is still valid
      try {
        const response = await api.get('/auth/me')
        user.value = response.user
      } catch (error) {
        // Token expired or invalid
        logout()
      }
    }

    initialized.value = true
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

    // Clear PWA caches and unregister service worker to ensure fresh content on next login
    try {
      // Clear all caches
      if ('caches' in window) {
        const cacheNames = await caches.keys()
        await Promise.all(cacheNames.map(name => caches.delete(name)))
      }

      // Unregister service workers
      if ('serviceWorker' in navigator) {
        const registrations = await navigator.serviceWorker.getRegistrations()
        await Promise.all(registrations.map(reg => reg.unregister()))
      }
    } catch (e) {
      console.error('Failed to clear caches:', e)
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

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

  function logout() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
    localStorage.removeItem('user')
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

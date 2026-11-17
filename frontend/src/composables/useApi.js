import { useAuthStore } from '@/stores/auth'

const API_BASE = '/api'
const DEFAULT_TIMEOUT = 20000
const UPLOAD_TIMEOUT = 60000

export const MESSAGES = {
  offline: "Can't reach Sunwise. Check your connection.",
  timeout: 'Sunwise took too long to answer. Try again.',
  server: 'Sunwise had a problem on its end. Try again in a minute.',
  expired: 'Your session ended. Please log in again.',
  generic: 'Something went wrong. Try again.'
}

/**
 * Error thrown for any failed API call. `status` is the HTTP status (0 when the
 * request never reached the server) and `offline` is true for network failures,
 * so callers can tell "no signal" apart from a real error.
 */
export class ApiError extends Error {
  constructor(message, { status = 0, offline = false, timeout = false, data = null } = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.offline = offline
    this.timeout = timeout
    this.data = data
  }
}

let handlingUnauthorized = false

async function handleUnauthorized() {
  if (handlingUnauthorized) return
  handlingUnauthorized = true
  try {
    const auth = useAuthStore()
    await auth.logout()
    // Imported lazily: the router imports the auth store, which imports this file.
    const { default: router } = await import('@/router')
    const current = router.currentRoute.value
    if (current.name !== 'login') {
      router.replace({ name: 'login', query: { redirect: current.fullPath } })
    }
    window.$toast?.info(MESSAGES.expired)
  } finally {
    handlingUnauthorized = false
  }
}

async function parseBody(response) {
  if (response.status === 204) return {}
  const text = await response.text()
  if (!text) return {}
  try {
    return JSON.parse(text)
  } catch (e) {
    return null
  }
}

async function send(url, options, { timeout = DEFAULT_TIMEOUT, skipAuthRedirect = false, hadToken = false } = {}) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeout)

  let response
  try {
    response = await fetch(url, { ...options, signal: controller.signal })
  } catch (e) {
    clearTimeout(timer)
    if (e.name === 'AbortError') {
      throw new ApiError(MESSAGES.timeout, { timeout: true, offline: !navigator.onLine })
    }
    throw new ApiError(MESSAGES.offline, { offline: true })
  }
  clearTimeout(timer)

  const json = await parseBody(response)

  if (!response.ok) {
    if (response.status === 401 && hadToken && !skipAuthRedirect) {
      handleUnauthorized()
      throw new ApiError(MESSAGES.expired, { status: 401, data: json })
    }
    let message = json?.error || json?.message
    if (!message) {
      message = response.status >= 500 ? MESSAGES.server : MESSAGES.generic
    }
    throw new ApiError(message, { status: response.status, data: json })
  }

  if (json === null) {
    // A 200 with a body that isn't JSON usually means a proxy or error page.
    throw new ApiError(MESSAGES.server, { status: response.status })
  }

  return json
}

export function useApi() {
  function authHeader() {
    const auth = useAuthStore()
    return auth.token ? { Authorization: `Bearer ${auth.token}` } : {}
  }

  async function request(method, endpoint, data = null, opts = {}) {
    const headers = { 'Content-Type': 'application/json', ...authHeader() }
    const options = { method, headers, cache: 'no-store' }
    if (data && method !== 'GET') {
      options.body = JSON.stringify(data)
    }
    return send(`${API_BASE}${endpoint}`, options, {
      ...opts,
      hadToken: !!headers.Authorization
    })
  }

  async function upload(endpoint, formData, opts = {}) {
    const headers = authHeader()
    return send(`${API_BASE}${endpoint}`, {
      method: 'POST',
      headers,
      body: formData,
      cache: 'no-store'
    }, {
      timeout: UPLOAD_TIMEOUT,
      ...opts,
      hadToken: !!headers.Authorization
    })
  }

  return {
    get: (endpoint, opts) => request('GET', endpoint, null, opts),
    post: (endpoint, data, opts) => request('POST', endpoint, data, opts),
    put: (endpoint, data, opts) => request('PUT', endpoint, data, opts),
    delete: (endpoint, opts) => request('DELETE', endpoint, null, opts),
    upload,
    // Alias for upload, for form data posts
    postForm: upload
  }
}

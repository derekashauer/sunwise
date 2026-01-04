import { useAuthStore } from '@/stores/auth'

const API_BASE = '/api'

export function useApi() {
  function getHeaders() {
    const auth = useAuthStore()
    const headers = {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-cache, no-store, must-revalidate',
      'Pragma': 'no-cache',
      'Expires': '0'
    }
    if (auth.token) {
      headers['Authorization'] = `Bearer ${auth.token}`
    }
    return headers
  }

  // Add cache-busting timestamp to URL
  function addCacheBuster(url) {
    const separator = url.includes('?') ? '&' : '?'
    return `${url}${separator}_t=${Date.now()}`
  }

  async function request(method, endpoint, data = null) {
    // Always add cache-buster to prevent any caching
    const url = addCacheBuster(`${API_BASE}${endpoint}`)
    const options = {
      method,
      headers: getHeaders(),
      cache: 'no-store'  // Force browser to never cache
    }

    if (data && method !== 'GET') {
      options.body = JSON.stringify(data)
    }

    const response = await fetch(url, options)
    const json = await response.json()

    if (!response.ok) {
      throw new Error(json.error || 'Request failed')
    }

    return json
  }

  async function upload(endpoint, formData) {
    const auth = useAuthStore()
    // Always add cache-buster
    const url = addCacheBuster(`${API_BASE}${endpoint}`)

    const headers = {
      'Cache-Control': 'no-cache, no-store, must-revalidate',
      'Pragma': 'no-cache',
      'Expires': '0'
    }
    if (auth.token) {
      headers['Authorization'] = `Bearer ${auth.token}`
    }

    const response = await fetch(url, {
      method: 'POST',
      headers,
      body: formData,
      cache: 'no-store'
    })

    const json = await response.json()

    if (!response.ok) {
      throw new Error(json.error || 'Upload failed')
    }

    return json
  }

  // Alias for upload - for form data posts
  const postForm = upload

  return {
    get: (endpoint) => request('GET', endpoint),
    post: (endpoint, data) => request('POST', endpoint, data),
    put: (endpoint, data) => request('PUT', endpoint, data),
    delete: (endpoint) => request('DELETE', endpoint),
    upload,
    postForm
  }
}

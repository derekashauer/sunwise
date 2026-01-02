import { useAuthStore } from '@/stores/auth'

const API_BASE = '/api'

export function useApi() {
  function getHeaders() {
    const auth = useAuthStore()
    const headers = {
      'Content-Type': 'application/json'
    }
    if (auth.token) {
      headers['Authorization'] = `Bearer ${auth.token}`
    }
    return headers
  }

  async function request(method, endpoint, data = null) {
    const url = `${API_BASE}${endpoint}`
    const options = {
      method,
      headers: getHeaders()
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
    const url = `${API_BASE}${endpoint}`

    const headers = {}
    if (auth.token) {
      headers['Authorization'] = `Bearer ${auth.token}`
    }

    const response = await fetch(url, {
      method: 'POST',
      headers,
      body: formData
    })

    const json = await response.json()

    if (!response.ok) {
      throw new Error(json.error || 'Upload failed')
    }

    return json
  }

  return {
    get: (endpoint) => request('GET', endpoint),
    post: (endpoint, data) => request('POST', endpoint, data),
    put: (endpoint, data) => request('PUT', endpoint, data),
    delete: (endpoint) => request('DELETE', endpoint),
    upload
  }
}

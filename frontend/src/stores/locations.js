import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'

export const useLocationsStore = defineStore('locations', () => {
  const locations = ref([])
  const loading = ref(false)
  const error = ref(null)

  const api = useApi()

  const locationsCount = computed(() => locations.value.length)

  async function fetchLocations() {
    loading.value = true
    error.value = null
    try {
      const response = await api.get('/locations')
      locations.value = response.locations
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function createLocation(name) {
    const response = await api.post('/locations', { name })
    locations.value.push(response.location)
    return response.location
  }

  async function updateLocation(id, name) {
    const response = await api.put(`/locations/${id}`, { name })
    const index = locations.value.findIndex(l => l.id === id)
    if (index !== -1) {
      locations.value[index] = response.location
    }
    return response.location
  }

  async function deleteLocation(id) {
    await api.delete(`/locations/${id}`)
    locations.value = locations.value.filter(l => l.id !== id)
  }

  async function getPlantsByLocation(locationId) {
    const response = await api.get(`/locations/${locationId}/plants`)
    return response
  }

  function getLocationById(id) {
    return locations.value.find(l => l.id === parseInt(id))
  }

  return {
    locations,
    loading,
    error,
    locationsCount,
    fetchLocations,
    createLocation,
    updateLocation,
    deleteLocation,
    getPlantsByLocation,
    getLocationById
  }
})

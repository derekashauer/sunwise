import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'

export const usePlantsStore = defineStore('plants', () => {
  const plants = ref([])
  const loading = ref(false)
  const error = ref(null)

  const api = useApi()

  const plantsCount = computed(() => plants.value.length)

  async function fetchPlants() {
    loading.value = true
    error.value = null
    try {
      const response = await api.get('/plants')
      plants.value = response.plants
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function getPlant(id) {
    const response = await api.get(`/plants/${id}`)
    return response.plant
  }

  async function createPlant(formData) {
    const response = await api.upload('/plants', formData)
    plants.value.unshift(response.plant)
    return response.plant
  }

  async function updatePlant(id, data) {
    const response = await api.put(`/plants/${id}`, data)
    const index = plants.value.findIndex(p => p.id === id)
    if (index !== -1) {
      plants.value[index] = response.plant
    }
    return response.plant
  }

  async function deletePlant(id) {
    await api.delete(`/plants/${id}`)
    plants.value = plants.value.filter(p => p.id !== id)
  }

  async function uploadPhoto(plantId, formData) {
    const response = await api.upload(`/plants/${plantId}/photo`, formData)
    return response.photo
  }

  async function getPhotos(plantId) {
    const response = await api.get(`/plants/${plantId}/photos`)
    return response.photos
  }

  async function regenerateCarePlan(plantId) {
    const response = await api.post(`/plants/${plantId}/care-plan/regenerate`)
    return response
  }

  function getPlantById(id) {
    return plants.value.find(p => p.id === parseInt(id))
  }

  return {
    plants,
    loading,
    error,
    plantsCount,
    fetchPlants,
    getPlant,
    createPlant,
    updatePlant,
    deletePlant,
    uploadPhoto,
    getPhotos,
    getPlantById,
    regenerateCarePlan
  }
})

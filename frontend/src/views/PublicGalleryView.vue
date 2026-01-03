<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const gallery = ref(null)
const loading = ref(true)
const error = ref(null)

onMounted(async () => {
  await loadGallery()
})

async function loadGallery() {
  loading.value = true
  error.value = null

  try {
    const token = route.params.token
    const response = await fetch(`/api/gallery/${token}`)
    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.error || 'Gallery not found')
    }

    gallery.value = data
  } catch (e) {
    error.value = e.message || 'Failed to load gallery'
  } finally {
    loading.value = false
  }
}

function getPlantImage(plant) {
  if (plant.thumbnail) {
    return `/uploads/plants/${plant.thumbnail}`
  }
  return '/icons/plant-placeholder.svg'
}

function getHealthEmoji(status) {
  const emojis = {
    thriving: '1f31f',
    healthy: '2705',
    struggling: '26a0-fe0f',
    critical: '1f6a8'
  }
  return emojis[status] || '1f331'
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center min-h-screen">
      <div class="text-center">
        <div class="w-12 h-12 border-4 border-plant-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
        <p class="text-gray-500">Loading gallery...</p>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex items-center justify-center min-h-screen">
      <div class="text-center p-8">
        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h1 class="text-xl font-semibold text-gray-900 mb-2">Gallery Not Found</h1>
        <p class="text-gray-500">{{ error }}</p>
      </div>
    </div>

    <!-- Gallery -->
    <div v-else class="max-w-4xl mx-auto px-4 py-8">
      <!-- Header -->
      <header class="text-center mb-8">
        <div class="w-16 h-16 bg-plant-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-1">
          {{ gallery.gallery_name || 'Plant Gallery' }}
        </h1>
        <p class="text-gray-500">{{ gallery.plant_count }} plants</p>
      </header>

      <!-- Plants grid -->
      <div v-if="gallery.plants.length > 0" class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <div
          v-for="plant in gallery.plants"
          :key="plant.id"
          class="bg-white rounded-2xl shadow-sm overflow-hidden"
        >
          <div class="aspect-square bg-gray-100">
            <img
              :src="getPlantImage(plant)"
              :alt="plant.name"
              class="w-full h-full object-cover"
            >
          </div>
          <div class="p-3">
            <h3 class="font-semibold text-gray-900 truncate">{{ plant.name }}</h3>
            <p v-if="plant.species" class="text-sm text-gray-500 truncate">{{ plant.species }}</p>
            <div class="flex items-center gap-2 mt-2">
              <span v-if="plant.health_status" class="text-sm">
                <img
                  :src="`https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/${getHealthEmoji(plant.health_status)}.svg`"
                  class="w-4 h-4 inline"
                  alt=""
                >
              </span>
              <span v-if="plant.location_name" class="text-xs text-gray-400">
                {{ plant.location_name }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="text-center py-12">
        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
        </svg>
        <p class="text-gray-500">No plants in this gallery yet</p>
      </div>

      <!-- Footer -->
      <footer class="text-center mt-12 pt-8 border-t border-gray-200">
        <p class="text-sm text-gray-400">
          Powered by <span class="text-plant-600 font-medium">Sunwise</span>
        </p>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'

const router = useRouter()
const api = useApi()

const plants = ref([])
const loading = ref(true)

onMounted(async () => {
  await loadArchivedPlants()
})

async function loadArchivedPlants() {
  loading.value = true
  try {
    const response = await api.get('/plants/archived')
    plants.value = response.plants || []
  } catch (e) {
    console.error('Failed to load archived plants:', e)
    window.$toast?.error('Failed to load graveyard')
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

function formatDate(dateString) {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

function viewPlant(plant) {
  router.push(`/plants/${plant.id}`)
}
</script>

<template>
  <div class="page-container">
    <!-- Header -->
    <div class="flex items-center gap-3 mb-6">
      <button @click="router.back()" class="p-2 -ml-2 rounded-xl hover:bg-gray-100">
        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </button>
      <div>
        <h1 class="text-xl font-bold text-gray-900">Plant Graveyard</h1>
        <p class="text-sm text-gray-500">In loving memory</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-12">
      <div class="w-8 h-8 border-3 border-gray-300 border-t-gray-600 rounded-full animate-spin"></div>
    </div>

    <!-- Empty State -->
    <div v-else-if="plants.length === 0" class="text-center py-12">
      <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
        </svg>
      </div>
      <h3 class="text-lg font-medium text-gray-900 mb-1">No plants here yet</h3>
      <p class="text-gray-500">This is where your plant memories live on.</p>
    </div>

    <!-- Plant List -->
    <div v-else class="space-y-3">
      <div
        v-for="plant in plants"
        :key="plant.id"
        @click="viewPlant(plant)"
        class="card p-4 flex items-center gap-4 cursor-pointer hover:shadow-md transition-shadow"
      >
        <!-- Plant Image -->
        <div class="w-16 h-16 rounded-xl overflow-hidden bg-gray-100 flex-shrink-0 relative">
          <img
            :src="getPlantImage(plant)"
            :alt="plant.name"
            class="w-full h-full object-cover grayscale opacity-75"
          >
          <!-- Memorial ribbon -->
          <div class="absolute inset-0 flex items-center justify-center">
            <div class="w-6 h-6 bg-white/80 rounded-full flex items-center justify-center">
              <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
              </svg>
            </div>
          </div>
        </div>

        <!-- Plant Info -->
        <div class="flex-1 min-w-0">
          <h3 class="font-semibold text-gray-900 truncate">{{ plant.name }}</h3>
          <p v-if="plant.species" class="text-sm text-gray-500 truncate">{{ plant.species }}</p>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-xs text-gray-400">
              Archived {{ formatDate(plant.archived_at) }}
            </span>
          </div>
          <p v-if="plant.death_reason" class="text-xs text-gray-500 mt-1 line-clamp-1">
            {{ plant.death_reason }}
          </p>
        </div>

        <!-- Arrow -->
        <svg class="w-5 h-5 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </div>
    </div>

    <!-- Footer note -->
    <p v-if="plants.length > 0" class="text-center text-sm text-gray-400 mt-8">
      {{ plants.length }} plant{{ plants.length !== 1 ? 's' : '' }} remembered
    </p>
  </div>
</template>

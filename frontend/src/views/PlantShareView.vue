<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const plant = ref(null)
const ownerName = ref('')
const loading = ref(true)
const error = ref(null)
const hasAttemptedLoad = ref(false)

const shareUrl = computed(() => window.location.href)

onMounted(async () => {
  // If route params are ready, load immediately
  if (route.params?.id) {
    await loadPlant()
  }
})

// Watch for route params in case they're not immediately available
watch(() => route.params?.id, async (newId) => {
  if (newId && !hasAttemptedLoad.value) {
    await loadPlant()
  }
}, { immediate: true })

async function loadPlant() {
  hasAttemptedLoad.value = true
  loading.value = true
  error.value = null

  try {
    // Wait for route to be ready
    const plantId = route.params?.id

    if (!plantId) {
      throw new Error('Plant ID not provided')
    }

    // Use absolute URL to avoid any routing issues
    const baseUrl = window.location.origin
    const response = await fetch(`${baseUrl}/api/plants/share/${plantId}`, {
      headers: {
        'Accept': 'application/json',
        'Cache-Control': 'no-cache'
      }
    })

    // Handle non-JSON responses (e.g., HTML error pages)
    const contentType = response.headers.get('content-type')
    if (!contentType || !contentType.includes('application/json')) {
      throw new Error(`Server returned non-JSON response (${response.status})`)
    }

    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.error || `Failed to load plant (${response.status})`)
    }

    if (!data.plant) {
      throw new Error('Plant data not found in response')
    }

    plant.value = data.plant
    ownerName.value = data.owner_name || 'Anonymous'
  } catch (e) {
    console.error('PlantShareView error:', e)
    error.value = e.message || 'Failed to load plant'
  } finally {
    loading.value = false
  }
}

function getPlantImage() {
  if (plant.value?.photo) {
    return `/uploads/plants/${plant.value.photo}`
  }
  return null
}

async function shareNative() {
  if (navigator.share) {
    try {
      await navigator.share({
        title: `${plant.value.name} - Sunwise`,
        text: plant.value.species ? `Check out my ${plant.value.species}!` : `Check out my plant ${plant.value.name}!`,
        url: shareUrl.value
      })
    } catch (e) {
      // User cancelled or error
    }
  } else {
    copyLink()
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(shareUrl.value)
    window.$toast?.success('Link copied!')
  } catch (e) {
    window.$toast?.error('Failed to copy link')
  }
}
</script>

<template>
  <div class="min-h-screen bg-gradient-to-b from-plant-50 to-white">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center min-h-screen">
      <div class="text-center">
        <div class="w-12 h-12 border-4 border-plant-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
        <p class="text-gray-500">Loading...</p>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex items-center justify-center min-h-screen">
      <div class="text-center p-8">
        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h1 class="text-xl font-semibold text-gray-900 mb-2">Plant Not Found</h1>
        <p class="text-gray-500 mb-6">{{ error }}</p>
        <a href="/" class="text-plant-600 hover:underline">Go to Sunwise</a>
      </div>
    </div>

    <!-- Plant -->
    <div v-else class="max-w-lg mx-auto px-4 py-8">
      <!-- Plant Photo -->
      <div class="relative aspect-square rounded-3xl overflow-hidden bg-gray-100 shadow-xl mb-6">
        <img
          v-if="getPlantImage()"
          :src="getPlantImage()"
          :alt="plant.name"
          class="w-full h-full object-cover"
        >
        <div v-else class="w-full h-full flex items-center justify-center bg-gradient-to-br from-plant-100 to-plant-200">
          <svg class="w-24 h-24 text-plant-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
          </svg>
        </div>
      </div>

      <!-- Plant Info -->
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ plant.name }}</h1>
        <p v-if="plant.species" class="text-lg text-plant-600 mb-2">{{ plant.species }}</p>
        <p class="text-gray-500 text-sm">Shared by {{ ownerName }}</p>
      </div>

      <!-- Share Button -->
      <div class="flex justify-center gap-3 mb-12">
        <button
          @click="shareNative"
          class="inline-flex items-center gap-2 px-6 py-3 bg-plant-500 text-white rounded-full font-medium hover:bg-plant-600 transition-colors shadow-lg"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          Share
        </button>
        <button
          @click="copyLink"
          class="inline-flex items-center gap-2 px-6 py-3 bg-white text-gray-700 rounded-full font-medium hover:bg-gray-50 transition-colors shadow border border-gray-200"
        >
          <img src="https://img.icons8.com/doodle-line/48/copy.png" alt="" class="w-5 h-5">
          Copy Link
        </button>
      </div>

      <!-- Signup Promo -->
      <div class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100 text-center">
        <div class="w-12 h-12 bg-plant-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-6 h-6 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
          </svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2">Track Your Plants with Sunwise</h2>
        <p class="text-gray-600 mb-4">AI-powered plant care reminders, health tracking, and more. It's free!</p>
        <a
          href="/register"
          class="inline-block px-8 py-3 bg-plant-500 text-white rounded-full font-semibold hover:bg-plant-600 transition-colors"
        >
          Sign Up Free
        </a>
        <p class="text-xs text-gray-400 mt-3">No credit card required</p>
      </div>

      <!-- Footer -->
      <div class="text-center mt-8 text-sm text-gray-400">
        <a href="/" class="hover:text-plant-600">Sunwise</a> - Smart Plant Care
      </div>
    </div>
  </div>
</template>

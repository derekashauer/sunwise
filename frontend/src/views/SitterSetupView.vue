<script setup>
import { ref, onMounted, computed } from 'vue'
import { usePlantsStore } from '@/stores/plants'
import { useApi } from '@/composables/useApi'

const plants = usePlantsStore()
const api = useApi()

const loading = ref(false)
const selectedPlants = ref([])
const startDate = ref('')
const endDate = ref('')
const sitterName = ref('')
const instructions = ref('')
const generatedLink = ref('')

onMounted(() => {
  plants.fetchPlants()

  // Set default dates
  const today = new Date()
  const nextWeek = new Date(today.getTime() + 7 * 24 * 60 * 60 * 1000)
  startDate.value = today.toISOString().split('T')[0]
  endDate.value = nextWeek.toISOString().split('T')[0]
})

const allSelected = computed(() =>
  plants.plants.length > 0 && selectedPlants.value.length === plants.plants.length
)

function toggleAll() {
  if (allSelected.value) {
    selectedPlants.value = []
  } else {
    selectedPlants.value = plants.plants.map(p => p.id)
  }
}

function togglePlant(id) {
  const index = selectedPlants.value.indexOf(id)
  if (index === -1) {
    selectedPlants.value.push(id)
  } else {
    selectedPlants.value.splice(index, 1)
  }
}

async function createSitterLink() {
  if (selectedPlants.value.length === 0) {
    window.$toast?.error('Please select at least one plant')
    return
  }

  loading.value = true
  try {
    const response = await api.post('/sitter/create', {
      plant_ids: selectedPlants.value,
      start_date: startDate.value,
      end_date: endDate.value,
      sitter_name: sitterName.value,
      instructions: instructions.value
    })
    generatedLink.value = response.url
    window.$toast?.success('Sitter link created!')
  } catch (e) {
    window.$toast?.error(e.message)
  } finally {
    loading.value = false
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(generatedLink.value)
    window.$toast?.success('Link copied!')
  } catch (e) {
    window.$toast?.error('Failed to copy')
  }
}

async function shareLink() {
  if (navigator.share) {
    try {
      await navigator.share({
        title: 'Plant Care Instructions',
        text: `Here are the plant care instructions for ${startDate.value} to ${endDate.value}`,
        url: generatedLink.value
      })
    } catch (e) {
      // User cancelled
    }
  } else {
    copyLink()
  }
}
</script>

<template>
  <div class="page-container">
    <h1 class="page-title">Sitter Mode</h1>
    <p class="text-gray-500 mb-6">Create a shareable link with care instructions for your plant sitter</p>

    <!-- Generated link -->
    <div v-if="generatedLink" class="card p-6 mb-6">
      <div class="w-12 h-12 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
        <svg class="w-6 h-6 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
      </div>
      <h2 class="text-lg font-semibold text-center mb-2">Link Created!</h2>
      <p class="text-sm text-gray-500 text-center mb-4">Share this link with your plant sitter</p>

      <div class="bg-gray-50 rounded-xl p-3 mb-4 break-all text-sm text-gray-600">
        {{ generatedLink }}
      </div>

      <div class="flex gap-3">
        <button @click="copyLink" class="btn-secondary flex-1">
          <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          Copy
        </button>
        <button @click="shareLink" class="btn-primary flex-1">
          <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          Share
        </button>
      </div>

      <button @click="generatedLink = ''" class="w-full text-gray-500 text-sm mt-4">
        Create another link
      </button>
    </div>

    <!-- Setup form -->
    <form v-else @submit.prevent="createSitterLink" class="space-y-6">
      <!-- Date range -->
      <div class="card p-4">
        <h2 class="font-semibold text-gray-900 mb-3">Date Range</h2>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-500 mb-1">Start</label>
            <input v-model="startDate" type="date" class="input" required>
          </div>
          <div>
            <label class="block text-sm text-gray-500 mb-1">End</label>
            <input v-model="endDate" type="date" class="input" required>
          </div>
        </div>
      </div>

      <!-- Plant selection -->
      <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-gray-900">Select Plants</h2>
          <button type="button" @click="toggleAll" class="text-sm text-plant-600">
            {{ allSelected ? 'Deselect All' : 'Select All' }}
          </button>
        </div>

        <div v-if="plants.loading" class="py-6 text-center text-gray-500">
          Loading plants...
        </div>

        <div v-else-if="plants.plants.length === 0" class="py-6 text-center text-gray-500">
          No plants yet. Add some plants first!
        </div>

        <div v-else class="space-y-2">
          <button
            v-for="plant in plants.plants"
            :key="plant.id"
            type="button"
            @click="togglePlant(plant.id)"
            class="w-full flex items-center gap-3 p-3 rounded-xl transition-colors"
            :class="selectedPlants.includes(plant.id) ? 'bg-plant-50' : 'hover:bg-gray-50'"
          >
            <div
              class="w-6 h-6 rounded-full border-2 flex items-center justify-center"
              :class="selectedPlants.includes(plant.id) ? 'bg-plant-500 border-plant-500' : 'border-gray-300'"
            >
              <svg v-if="selectedPlants.includes(plant.id)" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <div class="w-10 h-10 rounded-lg bg-gray-100 overflow-hidden">
              <img v-if="plant.thumbnail" :src="`/uploads/plants/${plant.thumbnail}`" class="w-full h-full object-cover">
            </div>
            <div class="flex-1 text-left">
              <p class="font-medium text-gray-900">{{ plant.name }}</p>
              <p v-if="plant.location" class="text-xs text-gray-500">{{ plant.location }}</p>
            </div>
          </button>
        </div>
      </div>

      <!-- Sitter info -->
      <div class="card p-4">
        <h2 class="font-semibold text-gray-900 mb-3">Sitter Info (Optional)</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm text-gray-500 mb-1">Sitter Name</label>
            <input v-model="sitterName" type="text" class="input" placeholder="e.g., Mom">
          </div>
          <div>
            <label class="block text-sm text-gray-500 mb-1">Special Instructions</label>
            <textarea
              v-model="instructions"
              rows="3"
              class="input resize-none"
              placeholder="Any additional notes..."
            ></textarea>
          </div>
        </div>
      </div>

      <button
        type="submit"
        :disabled="loading || selectedPlants.length === 0"
        class="btn-primary w-full"
      >
        <span v-if="loading" class="flex items-center justify-center gap-2">
          <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
          Creating...
        </span>
        <span v-else>Create Sitter Link</span>
      </button>
    </form>
  </div>
</template>

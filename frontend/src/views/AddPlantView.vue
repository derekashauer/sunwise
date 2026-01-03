<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useLocationsStore } from '@/stores/locations'
import { useApi } from '@/composables/useApi'

const router = useRouter()
const route = useRoute()
const plants = usePlantsStore()
const locationsStore = useLocationsStore()
const api = useApi()

const isEditing = computed(() => !!route.params.id)
const loading = ref(false)
const identifying = ref(false)
const error = ref('')
const showAddLocation = ref(false)
const newLocationName = ref('')

// Name generator
const generatingNames = ref(false)
const suggestedNames = ref([])
const showNameSuggestions = ref(false)

// Species picker modal
const showSpeciesPicker = ref(false)
const speciesCandidates = ref([])
const selectedSpecies = ref(null)
const pendingPlantId = ref(null)

const form = ref({
  name: '',
  species: '',
  pot_size: 'medium',
  soil_type: 'standard',
  light_condition: 'medium',
  location_id: null,
  notes: '',
  health_status: 'healthy'
})

const imageFile = ref(null)
const imagePreview = ref(null)

const potSizes = [
  { value: 'small', label: 'Small (< 4")' },
  { value: 'medium', label: 'Medium (4-8")' },
  { value: 'large', label: 'Large (8-12")' },
  { value: 'xlarge', label: 'Extra Large (12"+)' }
]

const soilTypes = [
  { value: 'standard', label: 'Standard Potting Mix' },
  { value: 'succulent', label: 'Succulent/Cactus Mix' },
  { value: 'orchid', label: 'Orchid Bark Mix' },
  { value: 'peat', label: 'Peat-Based Mix' },
  { value: 'custom', label: 'Custom Mix' }
]

const lightConditions = [
  { value: 'low', label: 'Low Light' },
  { value: 'medium', label: 'Medium/Indirect' },
  { value: 'bright', label: 'Bright Indirect' },
  { value: 'direct', label: 'Direct Sunlight' }
]

const healthStatuses = [
  { value: 'thriving', label: 'Thriving', emoji: '1f31f', desc: 'Growing vigorously' },
  { value: 'healthy', label: 'Healthy', emoji: '2705', desc: 'Doing well' },
  { value: 'struggling', label: 'Struggling', emoji: '26a0-fe0f', desc: 'Needs attention' },
  { value: 'critical', label: 'Critical', emoji: '1f6a8', desc: 'Urgent care needed' }
]

onMounted(async () => {
  await locationsStore.fetchLocations()

  if (isEditing.value) {
    try {
      const plant = await plants.getPlant(route.params.id)
      form.value = {
        name: plant.name,
        species: plant.species || '',
        pot_size: plant.pot_size || 'medium',
        soil_type: plant.soil_type || 'standard',
        light_condition: plant.light_condition || 'medium',
        location_id: plant.location_id || null,
        notes: plant.notes || '',
        health_status: plant.health_status || 'healthy'
      }
      if (plant.thumbnail) {
        imagePreview.value = `/uploads/plants/${plant.thumbnail}`
      }

      // Check if there are species candidates to show
      if (plant.species_candidates && !plant.species_confirmed) {
        try {
          speciesCandidates.value = JSON.parse(plant.species_candidates)
          if (speciesCandidates.value.length > 1) {
            pendingPlantId.value = plant.id
            showSpeciesPicker.value = true
          }
        } catch (e) {
          console.error('Failed to parse species candidates:', e)
        }
      }
    } catch (e) {
      error.value = 'Failed to load plant'
    }
  }
})

async function generateNames() {
  generatingNames.value = true
  try {
    const response = await api.get('/plants/generate-name?count=5')
    suggestedNames.value = response.names
    showNameSuggestions.value = true
  } catch (e) {
    window.$toast?.error('Failed to generate names')
  } finally {
    generatingNames.value = false
  }
}

function selectName(name) {
  form.value.name = name
  showNameSuggestions.value = false
}

async function addLocation() {
  if (!newLocationName.value.trim()) return

  try {
    const location = await locationsStore.createLocation(newLocationName.value.trim())
    form.value.location_id = location.id
    newLocationName.value = ''
    showAddLocation.value = false
  } catch (e) {
    error.value = e.message
  }
}

function handleImageSelect(event) {
  const file = event.target.files[0]
  if (!file) return

  if (!file.type.startsWith('image/')) {
    error.value = 'Please select an image file'
    return
  }

  imageFile.value = file
  imagePreview.value = URL.createObjectURL(file)
}

async function handleSubmit() {
  if (!form.value.name.trim()) {
    error.value = 'Please enter a plant name'
    return
  }

  if (!isEditing.value && !imageFile.value) {
    error.value = 'Please add a photo of your plant'
    return
  }

  loading.value = true
  error.value = ''

  try {
    const formData = new FormData()
    Object.keys(form.value).forEach(key => {
      if (form.value[key]) {
        formData.append(key, form.value[key])
      }
    })

    if (imageFile.value) {
      formData.append('image', imageFile.value)
    }

    if (isEditing.value) {
      await plants.updatePlant(route.params.id, form.value)
      window.$toast?.success('Plant updated!')
    } else {
      const plant = await plants.createPlant(formData)
      window.$toast?.success('Plant added! AI is analyzing...')

      // Check for species candidates after a short delay
      setTimeout(async () => {
        try {
          const updatedPlant = await plants.getPlant(plant.id)
          if (updatedPlant.species_candidates && !updatedPlant.species_confirmed) {
            const candidates = JSON.parse(updatedPlant.species_candidates)
            if (candidates.length > 1) {
              speciesCandidates.value = candidates
              pendingPlantId.value = plant.id
              showSpeciesPicker.value = true
              return
            }
          }
        } catch (e) {
          console.error('Failed to check species candidates:', e)
        }
        router.replace(`/plants/${plant.id}`)
      }, 3000)
      return
    }

    router.back()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function confirmSpecies() {
  if (!selectedSpecies.value || !pendingPlantId.value) return

  try {
    await api.post(`/plants/${pendingPlantId.value}/confirm-species`, {
      species: selectedSpecies.value
    })
    window.$toast?.success('Species confirmed!')
    showSpeciesPicker.value = false
    router.replace(`/plants/${pendingPlantId.value}`)
  } catch (e) {
    window.$toast?.error('Failed to confirm species')
  }
}

function skipSpeciesSelection() {
  showSpeciesPicker.value = false
  if (pendingPlantId.value) {
    router.replace(`/plants/${pendingPlantId.value}`)
  }
}
</script>

<template>
  <div class="page-container">
    <header class="flex items-center gap-4 mb-6">
      <button @click="router.back()" class="btn-ghost p-2 -ml-2">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </button>
      <h1 class="page-title mb-0">{{ isEditing ? 'Edit Plant' : 'Add Plant' }}</h1>
    </header>

    <form @submit.prevent="handleSubmit" class="space-y-6">
      <div v-if="error" class="p-3 bg-red-50 text-red-700 text-sm rounded-xl">
        {{ error }}
      </div>

      <!-- Photo upload -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Photo</label>
        <div class="relative">
          <input
            type="file"
            accept="image/*"
            capture="environment"
            @change="handleImageSelect"
            class="hidden"
            id="plant-image"
          >
          <label
            for="plant-image"
            class="block cursor-pointer"
          >
            <div
              v-if="imagePreview"
              class="aspect-square rounded-2xl overflow-hidden bg-gray-100"
            >
              <img :src="imagePreview" alt="Plant preview" class="w-full h-full object-cover">
            </div>
            <div
              v-else
              class="aspect-square rounded-2xl border-2 border-dashed border-gray-300 flex flex-col items-center justify-center bg-gray-50 hover:bg-gray-100 transition-colors"
            >
              <svg class="w-12 h-12 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <span class="text-sm text-gray-500">Tap to add photo</span>
              <span class="text-xs text-gray-400 mt-1">AI will identify your plant</span>
            </div>
          </label>
        </div>
      </div>

      <!-- Name with dice roll -->
      <div>
        <div class="flex items-center justify-between mb-1">
          <label for="name" class="block text-sm font-medium text-gray-700">Name *</label>
          <button
            type="button"
            @click="generateNames"
            :disabled="generatingNames"
            class="text-sm text-plant-600 hover:text-plant-700 flex items-center gap-1"
          >
            <svg v-if="generatingNames" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            Random name
          </button>
        </div>
        <input
          id="name"
          v-model="form.name"
          type="text"
          class="input"
          placeholder="e.g., Living Room Monstera"
        >
        <!-- Name suggestions dropdown -->
        <div v-if="showNameSuggestions && suggestedNames.length > 0" class="mt-2 p-2 bg-white border border-gray-200 rounded-xl shadow-lg">
          <p class="text-xs text-gray-500 mb-2">Pick a name:</p>
          <div class="space-y-1">
            <button
              v-for="name in suggestedNames"
              :key="name"
              type="button"
              @click="selectName(name)"
              class="w-full text-left px-3 py-2 text-sm rounded-lg hover:bg-plant-50 hover:text-plant-700 transition-colors"
            >
              {{ name }}
            </button>
          </div>
          <button
            type="button"
            @click="generateNames"
            :disabled="generatingNames"
            class="w-full mt-2 text-xs text-plant-600 hover:text-plant-700"
          >
            Roll again
          </button>
        </div>
      </div>

      <!-- Species (auto-filled by AI) -->
      <div>
        <label for="species" class="block text-sm font-medium text-gray-700 mb-1">
          Species
          <span class="text-gray-400 font-normal">(AI will identify)</span>
        </label>
        <input
          id="species"
          v-model="form.species"
          type="text"
          class="input"
          placeholder="Will be auto-detected"
        >
      </div>

      <!-- Location -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
        <div v-if="!showAddLocation">
          <div class="flex flex-wrap gap-2 mb-2">
            <button
              v-for="location in locationsStore.locations"
              :key="location.id"
              type="button"
              @click="form.location_id = location.id"
              class="px-3 py-2 rounded-xl border-2 text-sm transition-all"
              :class="form.location_id === location.id
                ? 'border-plant-500 bg-plant-50 text-plant-700'
                : 'border-gray-200 hover:border-gray-300 text-gray-700'"
            >
              {{ location.name }}
            </button>
            <button
              type="button"
              @click="showAddLocation = true"
              class="px-3 py-2 rounded-xl border-2 border-dashed border-gray-300 text-sm text-gray-500 hover:border-gray-400 hover:text-gray-600"
            >
              + Add Location
            </button>
          </div>
          <button
            v-if="form.location_id"
            type="button"
            @click="form.location_id = null"
            class="text-sm text-gray-500 hover:text-gray-700"
          >
            Clear selection
          </button>
        </div>
        <div v-else class="flex gap-2">
          <input
            v-model="newLocationName"
            type="text"
            class="input flex-1"
            placeholder="e.g., Living Room"
            @keyup.enter="addLocation"
          >
          <button type="button" @click="addLocation" class="btn-primary px-4">Add</button>
          <button type="button" @click="showAddLocation = false" class="btn-secondary px-4">Cancel</button>
        </div>
      </div>

      <!-- Pot size -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Pot Size</label>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="size in potSizes"
            :key="size.value"
            type="button"
            @click="form.pot_size = size.value"
            class="p-3 rounded-xl border-2 text-left transition-all"
            :class="form.pot_size === size.value
              ? 'border-plant-500 bg-plant-50'
              : 'border-gray-200 hover:border-gray-300'"
          >
            <span class="text-sm font-medium" :class="form.pot_size === size.value ? 'text-plant-700' : 'text-gray-700'">
              {{ size.label }}
            </span>
          </button>
        </div>
      </div>

      <!-- Soil type -->
      <div>
        <label for="soil" class="block text-sm font-medium text-gray-700 mb-1">Soil Type</label>
        <select id="soil" v-model="form.soil_type" class="input">
          <option v-for="soil in soilTypes" :key="soil.value" :value="soil.value">
            {{ soil.label }}
          </option>
        </select>
      </div>

      <!-- Light condition -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Light Condition</label>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="light in lightConditions"
            :key="light.value"
            type="button"
            @click="form.light_condition = light.value"
            class="p-3 rounded-xl border-2 text-left transition-all"
            :class="form.light_condition === light.value
              ? 'border-plant-500 bg-plant-50'
              : 'border-gray-200 hover:border-gray-300'"
          >
            <span class="text-sm font-medium" :class="form.light_condition === light.value ? 'text-plant-700' : 'text-gray-700'">
              {{ light.label }}
            </span>
          </button>
        </div>
      </div>

      <!-- Health Status (only show when editing) -->
      <div v-if="isEditing">
        <label class="block text-sm font-medium text-gray-700 mb-2">Health Status</label>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="status in healthStatuses"
            :key="status.value"
            type="button"
            @click="form.health_status = status.value"
            class="p-3 rounded-xl border-2 text-left transition-all flex items-center gap-2"
            :class="form.health_status === status.value
              ? 'border-plant-500 bg-plant-50'
              : 'border-gray-200 hover:border-gray-300'"
          >
            <img
              :src="`https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/${status.emoji}.svg`"
              class="w-5 h-5"
              alt=""
            >
            <div>
              <span class="text-sm font-medium block" :class="form.health_status === status.value ? 'text-plant-700' : 'text-gray-700'">
                {{ status.label }}
              </span>
              <span class="text-xs text-gray-500">{{ status.desc }}</span>
            </div>
          </button>
        </div>
      </div>

      <!-- Notes -->
      <div>
        <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
        <textarea
          id="notes"
          v-model="form.notes"
          rows="3"
          class="input resize-none"
          placeholder="Any special care notes..."
        ></textarea>
      </div>

      <!-- Submit -->
      <button
        type="submit"
        :disabled="loading"
        class="btn-primary w-full"
      >
        <span v-if="loading" class="flex items-center justify-center gap-2">
          <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
          {{ isEditing ? 'Saving...' : 'Adding Plant...' }}
        </span>
        <span v-else>{{ isEditing ? 'Save Changes' : 'Add Plant' }}</span>
      </button>
    </form>

    <!-- Species Picker Modal -->
    <div v-if="showSpeciesPicker" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-2xl max-w-md w-full max-h-[80vh] overflow-auto">
        <div class="p-4 border-b">
          <h2 class="text-lg font-semibold text-gray-900">Confirm Plant Species</h2>
          <p class="text-sm text-gray-500 mt-1">AI detected multiple possible species. Which one is correct?</p>
        </div>

        <div class="p-4 space-y-2">
          <button
            v-for="candidate in speciesCandidates"
            :key="candidate.species"
            @click="selectedSpecies = candidate.species"
            class="w-full p-3 rounded-xl border-2 text-left transition-all flex items-center justify-between"
            :class="selectedSpecies === candidate.species
              ? 'border-plant-500 bg-plant-50'
              : 'border-gray-200 hover:border-gray-300'"
          >
            <div>
              <span class="font-medium text-gray-900">{{ candidate.species }}</span>
              <span class="text-xs text-gray-500 ml-2">{{ Math.round(candidate.confidence * 100) }}% confidence</span>
            </div>
            <div
              v-if="selectedSpecies === candidate.species"
              class="w-5 h-5 bg-plant-500 rounded-full flex items-center justify-center"
            >
              <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
          </button>
        </div>

        <div class="p-4 border-t flex gap-2">
          <button
            @click="skipSpeciesSelection"
            class="btn-secondary flex-1"
          >
            Skip
          </button>
          <button
            @click="confirmSpecies"
            :disabled="!selectedSpecies"
            class="btn-primary flex-1"
            :class="{ 'opacity-50 cursor-not-allowed': !selectedSpecies }"
          >
            Confirm
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useLocationsStore } from '@/stores/locations'
import { useApi } from '@/composables/useApi'
import LoadingOverlay from '@/components/common/LoadingOverlay.vue'

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
const customSpecies = ref('')
const confirmingSpecies = ref(false)

// Care plan regeneration prompt
const showCarePlanPrompt = ref(false)
const regeneratingCarePlan = ref(false)

// Share modal
const showShareModal = ref(false)
const newPlantForShare = ref(null)

const form = ref({
  name: '',
  species: '',
  pot_size: 'medium',
  soil_type: 'standard',
  light_condition: 'medium',
  location_id: null,
  notes: '',
  health_status: 'healthy',
  is_propagation: false,
  propagation_date: null,
  parent_plant_id: null,
  has_grow_light: false,
  grow_light_hours: null,
  has_drainage: true
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
  { value: 'moss', label: 'Moss Ball' },
  { value: 'water', label: 'Water (Propagation)' },
  { value: 'rooting', label: 'Rooting Medium' },
  { value: 'custom', label: 'Custom Mix' }
]

const lightConditions = [
  { value: 'low', label: 'Low Light' },
  { value: 'medium', label: 'Medium/Indirect' },
  { value: 'bright', label: 'Bright Indirect' },
  { value: 'direct', label: 'Direct Sunlight' }
]

// Loading overlay state
const isProcessing = computed(() => loading.value || identifying.value || confirmingSpecies.value || regeneratingCarePlan.value)
const loadingMessage = computed(() => {
  if (identifying.value) return 'Identifying your plant...'
  if (confirmingSpecies.value) return 'Confirming species...'
  if (regeneratingCarePlan.value) return 'Updating care plan...'
  if (loading.value) return isEditing.value ? 'Saving changes...' : 'Adding your plant...'
  return 'Loading...'
})

const healthStatuses = [
  { value: 'thriving', label: 'Thriving', emoji: '1f31f', desc: 'Growing vigorously' },
  { value: 'healthy', label: 'Healthy', emoji: '2705', desc: 'Doing well' },
  { value: 'struggling', label: 'Struggling', emoji: '26a0-fe0f', desc: 'Needs attention' },
  { value: 'critical', label: 'Critical', emoji: '1f6a8', desc: 'Urgent care needed' }
]

// All plants for parent selection
const allPlants = ref([])

onMounted(async () => {
  await locationsStore.fetchLocations()

  // Load all plants for parent selection
  await plants.fetchPlants()
  allPlants.value = plants.plants.filter(p => !p.is_propagation) // Only show non-propagations as parents

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
        health_status: plant.health_status || 'healthy',
        is_propagation: !!plant.is_propagation,
        propagation_date: plant.propagation_date || null,
        parent_plant_id: plant.parent_plant_id || null,
        has_grow_light: !!plant.has_grow_light,
        grow_light_hours: plant.grow_light_hours || null,
        has_drainage: plant.has_drainage !== 0
      }
      if (plant.thumbnail) {
        imagePreview.value = `/uploads/plants/${plant.thumbnail}`
      }

      // Check if there are species candidates to show
      if (plant.species_candidates && !plant.species_confirmed) {
        try {
          speciesCandidates.value = JSON.parse(plant.species_candidates)
          if (speciesCandidates.value.length >= 1) {
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
      const val = form.value[key]
      // Convert booleans to 0/1 for PHP
      if (typeof val === 'boolean') {
        formData.append(key, val ? '1' : '0')
      } else if (val !== null && val !== '') {
        formData.append(key, val)
      }
    })

    if (imageFile.value) {
      formData.append('image', imageFile.value)
    }

    if (isEditing.value) {
      await plants.updatePlant(route.params.id, form.value)
      window.$toast?.success('Plant updated!')
      // Show care plan regeneration prompt
      showCarePlanPrompt.value = true
      loading.value = false
      return
    } else {
      const plant = await plants.createPlant(formData)
      window.$toast?.success('Plant added! AI is analyzing...')

      // Check for species candidates after a short delay
      setTimeout(async () => {
        try {
          const updatedPlant = await plants.getPlant(plant.id)
          if (updatedPlant.species_candidates && !updatedPlant.species_confirmed) {
            const candidates = JSON.parse(updatedPlant.species_candidates)
            if (candidates.length >= 1) {
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
  const speciesName = customSpecies.value.trim() || selectedSpecies.value
  if (!speciesName || !pendingPlantId.value) return

  confirmingSpecies.value = true
  try {
    await api.post(`/plants/${pendingPlantId.value}/confirm-species`, {
      species: speciesName
    })
    window.$toast?.success('Species confirmed!')
    showSpeciesPicker.value = false
    // Show share modal for new plants
    newPlantForShare.value = { id: pendingPlantId.value, name: form.value.name }
    showShareModal.value = true
  } catch (e) {
    window.$toast?.error('Failed to confirm species')
  } finally {
    confirmingSpecies.value = false
  }
}

function selectSpeciesCandidate(species) {
  selectedSpecies.value = species
  customSpecies.value = '' // Clear custom input when selecting a candidate
}

function skipSpeciesSelection() {
  showSpeciesPicker.value = false
  if (pendingPlantId.value) {
    // Show share modal for new plants
    newPlantForShare.value = { id: pendingPlantId.value, name: form.value.name }
    showShareModal.value = true
  }
}

function getShareUrl() {
  return `${window.location.origin}/plant/${newPlantForShare.value?.id}`
}

async function shareNative() {
  if (navigator.share) {
    try {
      await navigator.share({
        title: `${newPlantForShare.value?.name} - Sunwise`,
        text: `Check out my plant ${newPlantForShare.value?.name}!`,
        url: getShareUrl()
      })
    } catch (e) {
      // User cancelled
    }
  } else {
    copyShareLink()
  }
}

async function copyShareLink() {
  try {
    await navigator.clipboard.writeText(getShareUrl())
    window.$toast?.success('Link copied!')
  } catch (e) {
    window.$toast?.error('Failed to copy link')
  }
}

function closeShareAndNavigate() {
  showShareModal.value = false
  if (newPlantForShare.value?.id) {
    router.replace(`/plants/${newPlantForShare.value.id}`)
  }
}

async function regenerateCarePlan() {
  regeneratingCarePlan.value = true
  try {
    await plants.regenerateCarePlan(route.params.id)
    window.$toast?.success('Care plan updated!')
    showCarePlanPrompt.value = false
    router.back()
  } catch (e) {
    window.$toast?.error('Failed to update care plan')
  } finally {
    regeneratingCarePlan.value = false
  }
}

function skipCarePlanUpdate() {
  showCarePlanPrompt.value = false
  router.back()
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
      <div v-if="error" class="p-3 bg-terracotta-50 text-terracotta-700 text-sm rounded-xl border border-terracotta-200">
        {{ error }}
      </div>

      <!-- Photo upload -->
      <div>
        <label class="form-label">Photo</label>
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
              class="aspect-square rounded-2xl overflow-hidden bg-cream-200"
            >
              <img :src="imagePreview" alt="Plant preview" class="w-full h-full object-cover">
            </div>
            <div
              v-else
              class="aspect-square rounded-2xl border-2 border-dashed border-sage-300 flex flex-col items-center justify-center bg-cream-100 hover:bg-cream-200 transition-colors"
            >
              <img
                src="https://img.icons8.com/doodle/96/camera.png"
                alt="add photo"
                class="w-16 h-16 mb-2"
              >
              <span class="text-sm text-charcoal-500">Tap to add photo</span>
              <span class="text-xs text-charcoal-400 mt-1">AI will identify your plant</span>
            </div>
          </label>
        </div>
      </div>

      <!-- Name with dice roll -->
      <div>
        <div class="flex items-center justify-between mb-1">
          <label for="name" class="form-label mb-0">Name *</label>
          <button
            type="button"
            @click="generateNames"
            :disabled="generatingNames"
            class="text-sm text-sage-600 hover:text-sage-700 flex items-center gap-1"
          >
            <img
              v-if="generatingNames"
              src="https://img.icons8.com/doodle/48/watering-can.png"
              alt="loading"
              class="w-4 h-4 loading-watering-can"
            >
            <img
              v-else
              src="https://img.icons8.com/doodle/48/dice.png"
              alt=""
              class="w-4 h-4"
            >
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
        <div v-if="showNameSuggestions && suggestedNames.length > 0" class="mt-2 p-2 bg-white border border-cream-200 rounded-xl shadow-warm">
          <p class="text-xs text-charcoal-400 mb-2">Pick a name:</p>
          <div class="space-y-1">
            <button
              v-for="name in suggestedNames"
              :key="name"
              type="button"
              @click="selectName(name)"
              class="w-full text-left px-3 py-2 text-sm rounded-lg hover:bg-sage-50 hover:text-sage-700 transition-colors"
            >
              {{ name }}
            </button>
          </div>
          <button
            type="button"
            @click="generateNames"
            :disabled="generatingNames"
            class="w-full mt-2 text-xs text-sage-600 hover:text-sage-700"
          >
            Roll again
          </button>
        </div>
      </div>

      <!-- Species (auto-filled by AI) -->
      <div>
        <label for="species" class="form-label">
          Species
          <span class="text-charcoal-300 font-normal">(AI will identify)</span>
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
        <label class="form-label">Location</label>
        <div v-if="!showAddLocation">
          <div class="flex flex-wrap gap-2 mb-2">
            <button
              v-for="location in locationsStore.locations"
              :key="location.id"
              type="button"
              @click="form.location_id = location.id"
              class="px-3 py-2 rounded-xl border-2 text-sm transition-all"
              :class="form.location_id === location.id
                ? 'border-sage-500 bg-sage-50 text-sage-700'
                : 'border-cream-300 hover:border-charcoal-200 text-charcoal-600'"
            >
              {{ location.name }}
            </button>
            <button
              type="button"
              @click="showAddLocation = true"
              class="px-3 py-2 rounded-xl border-2 border-dashed border-sage-300 text-sm text-charcoal-400 hover:border-sage-400 hover:text-charcoal-600"
            >
              + Add Location
            </button>
          </div>
          <button
            v-if="form.location_id"
            type="button"
            @click="form.location_id = null"
            class="text-sm text-charcoal-400 hover:text-charcoal-600"
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

      <!-- Pot size (hide for propagations in water) -->
      <div v-if="!form.is_propagation || (form.soil_type !== 'water' && form.soil_type !== 'rooting')">
        <label class="form-label">Pot Size</label>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="size in potSizes"
            :key="size.value"
            type="button"
            @click="form.pot_size = size.value"
            class="p-3 rounded-xl border-2 text-left transition-all"
            :class="form.pot_size === size.value
              ? 'border-sage-500 bg-sage-50'
              : 'border-cream-300 hover:border-charcoal-200'"
          >
            <span class="text-sm font-medium" :class="form.pot_size === size.value ? 'text-sage-700' : 'text-charcoal-600'">
              {{ size.label }}
            </span>
          </button>
        </div>
      </div>

      <!-- Soil type (hide for propagations) -->
      <div v-if="!form.is_propagation">
        <label for="soil" class="form-label">Soil Type</label>
        <select id="soil" v-model="form.soil_type" class="input">
          <option v-for="soil in soilTypes" :key="soil.value" :value="soil.value">
            {{ soil.label }}
          </option>
        </select>
      </div>

      <!-- Drainage (hide for water propagations) -->
      <div v-if="!form.is_propagation || (form.soil_type !== 'water')" class="flex items-center gap-3 p-3 rounded-xl border-2 border-gray-200">
        <input
          type="checkbox"
          id="drainage"
          v-model="form.has_drainage"
          class="w-5 h-5 rounded border-gray-300 text-plant-600 focus:ring-plant-500"
        >
        <label for="drainage" class="flex-1 cursor-pointer">
          <span class="text-sm font-medium text-gray-700">Pot has drainage holes</span>
          <span class="text-xs text-gray-500 block">Drainage helps prevent root rot</span>
        </label>
        <img
          src="https://img.icons8.com/doodle/48/potted-plant.png"
          alt=""
          class="w-6 h-6 opacity-50"
        >
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

      <!-- Grow Light -->
      <div class="p-4 rounded-xl border-2 border-gray-200 space-y-3">
        <label class="flex items-center gap-3 cursor-pointer">
          <input
            type="checkbox"
            v-model="form.has_grow_light"
            class="w-5 h-5 rounded border-gray-300 text-plant-600 focus:ring-plant-500"
          >
          <div>
            <span class="text-sm font-medium text-gray-700">Has Grow Light</span>
            <span class="text-xs text-gray-500 block">Supplemental artificial lighting</span>
          </div>
        </label>
        <div v-if="form.has_grow_light" class="ml-8">
          <label for="grow-hours" class="block text-sm text-gray-600 mb-1">Hours per day</label>
          <input
            id="grow-hours"
            type="number"
            v-model="form.grow_light_hours"
            min="1"
            max="24"
            class="input w-24"
            placeholder="12"
          >
        </div>
      </div>

      <!-- Propagation -->
      <div class="p-4 rounded-xl border-2 border-gray-200 space-y-3">
        <label class="flex items-center gap-3 cursor-pointer">
          <input
            type="checkbox"
            v-model="form.is_propagation"
            class="w-5 h-5 rounded border-gray-300 text-plant-600 focus:ring-plant-500"
          >
          <div>
            <span class="text-sm font-medium text-gray-700">This is a Propagation</span>
            <span class="text-xs text-gray-500 block">Cutting or baby plant in water/rooting medium</span>
          </div>
        </label>
        <div v-if="form.is_propagation" class="ml-8 space-y-3">
          <div>
            <label class="block text-sm text-gray-600 mb-1">Propagation Medium</label>
            <div class="flex gap-2">
              <button
                type="button"
                @click="form.soil_type = 'water'"
                class="flex-1 p-2 rounded-lg border-2 text-sm transition-all"
                :class="form.soil_type === 'water'
                  ? 'border-plant-500 bg-plant-50 text-plant-700'
                  : 'border-gray-200 hover:border-gray-300 text-gray-700'"
              >
                💧 Water
              </button>
              <button
                type="button"
                @click="form.soil_type = 'rooting'"
                class="flex-1 p-2 rounded-lg border-2 text-sm transition-all"
                :class="form.soil_type === 'rooting'
                  ? 'border-plant-500 bg-plant-50 text-plant-700'
                  : 'border-gray-200 hover:border-gray-300 text-gray-700'"
              >
                🌱 Rooting Medium
              </button>
            </div>
          </div>
          <div>
            <label for="prop-date" class="block text-sm text-gray-600 mb-1">Propagation Date</label>
            <input
              id="prop-date"
              type="date"
              v-model="form.propagation_date"
              class="input"
            >
          </div>
          <div v-if="allPlants.length > 0">
            <label for="parent-plant" class="block text-sm text-gray-600 mb-1">Parent Plant (optional)</label>
            <select id="parent-plant" v-model="form.parent_plant_id" class="input">
              <option :value="null">None</option>
              <option v-for="p in allPlants" :key="p.id" :value="p.id">
                {{ p.name }} ({{ p.species || 'Unknown species' }})
              </option>
            </select>
          </div>
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
          <p class="text-sm text-gray-500 mt-1">AI detected possible species. Select one or enter your own.</p>
        </div>

        <div class="p-4 space-y-2">
          <div
            v-for="candidate in speciesCandidates"
            :key="candidate.species"
            class="p-3 rounded-xl border-2 transition-all"
            :class="selectedSpecies === candidate.species && !customSpecies
              ? 'border-plant-500 bg-plant-50'
              : 'border-gray-200'"
          >
            <button
              @click="selectSpeciesCandidate(candidate.species)"
              class="w-full text-left flex items-center justify-between"
            >
              <div>
                <span class="font-medium text-gray-900">{{ candidate.species }}</span>
                <span class="text-xs text-gray-500 ml-2">{{ Math.round(candidate.confidence * 100) }}%</span>
              </div>
              <div
                v-if="selectedSpecies === candidate.species && !customSpecies"
                class="w-5 h-5 bg-plant-500 rounded-full flex items-center justify-center"
              >
                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                </svg>
              </div>
            </button>
            <!-- Google Images link -->
            <a
              :href="`https://www.google.com/search?tbm=isch&q=${encodeURIComponent(candidate.species + ' plant')}`"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1 text-xs text-plant-600 hover:text-plant-700 mt-1"
              @click.stop
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              View images to confirm
            </a>
          </div>

          <!-- Custom species input -->
          <div class="pt-2 border-t mt-3">
            <label class="block text-sm text-gray-600 mb-1">Or enter a different species:</label>
            <input
              v-model="customSpecies"
              type="text"
              class="input text-sm"
              placeholder="Type species name..."
              @input="selectedSpecies = null"
            >
          </div>
        </div>

        <div class="p-4 border-t flex gap-2">
          <button
            @click="skipSpeciesSelection"
            class="btn-secondary flex-1"
            :disabled="confirmingSpecies"
          >
            Skip
          </button>
          <button
            @click="confirmSpecies"
            :disabled="(!selectedSpecies && !customSpecies.trim()) || confirmingSpecies"
            class="btn-primary flex-1"
            :class="{ 'opacity-50 cursor-not-allowed': (!selectedSpecies && !customSpecies.trim()) || confirmingSpecies }"
          >
            <span v-if="confirmingSpecies" class="flex items-center justify-center gap-2">
              <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Confirming...
            </span>
            <span v-else>Confirm</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Care Plan Regeneration Prompt -->
    <div v-if="showCarePlanPrompt" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-2xl max-w-sm w-full p-6">
        <div class="w-12 h-12 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
          <svg class="w-6 h-6 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </div>
        <h3 class="text-lg font-semibold text-center text-gray-900 mb-2">Update Care Plan?</h3>
        <p class="text-gray-500 text-sm text-center mb-6">
          You've made changes to this plant. Would you like AI to generate an updated care schedule based on the new information?
        </p>
        <div class="flex gap-3">
          <button
            @click="skipCarePlanUpdate"
            :disabled="regeneratingCarePlan"
            class="btn-secondary flex-1"
          >
            Keep Current
          </button>
          <button
            @click="regenerateCarePlan"
            :disabled="regeneratingCarePlan"
            class="btn-primary flex-1"
          >
            <span v-if="regeneratingCarePlan" class="flex items-center justify-center gap-2">
              <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Updating...
            </span>
            <span v-else>Update Plan</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Share Modal -->
    <div v-if="showShareModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-2xl max-w-sm w-full p-6 text-center">
        <div class="w-16 h-16 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
          <svg class="w-8 h-8 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h3 class="text-xl font-bold text-gray-900 mb-2">Plant Added!</h3>
        <p class="text-gray-500 mb-6">Share {{ newPlantForShare?.name }} with friends and family</p>

        <div class="space-y-3 mb-6">
          <button
            @click="shareNative"
            class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 bg-plant-500 text-white rounded-xl font-medium hover:bg-plant-600 transition-colors"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
            </svg>
            Share
          </button>
          <button
            @click="copyShareLink"
            class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl font-medium hover:bg-gray-200 transition-colors"
          >
            <img src="https://img.icons8.com/doodle-line/48/copy.png" alt="" class="w-5 h-5">
            Copy Link
          </button>
        </div>

        <button
          @click="closeShareAndNavigate"
          class="text-gray-500 text-sm hover:text-gray-700"
        >
          Maybe later
        </button>
      </div>
    </div>

    <!-- Loading overlay -->
    <LoadingOverlay :visible="isProcessing" :message="loadingMessage" />
  </div>
</template>

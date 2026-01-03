<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useLocationsStore } from '@/stores/locations'

const router = useRouter()
const route = useRoute()
const plants = usePlantsStore()
const locationsStore = useLocationsStore()

const isEditing = computed(() => !!route.params.id)
const loading = ref(false)
const identifying = ref(false)
const error = ref('')
const showAddLocation = ref(false)
const newLocationName = ref('')

const form = ref({
  name: '',
  species: '',
  pot_size: 'medium',
  soil_type: 'standard',
  light_condition: 'medium',
  location_id: null,
  notes: ''
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
        notes: plant.notes || ''
      }
      if (plant.thumbnail) {
        imagePreview.value = `/uploads/plants/${plant.thumbnail}`
      }
    } catch (e) {
      error.value = 'Failed to load plant'
    }
  }
})

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
      router.replace(`/plants/${plant.id}`)
      return
    }

    router.back()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
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

      <!-- Name -->
      <div>
        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
        <input
          id="name"
          v-model="form.name"
          type="text"
          class="input"
          placeholder="e.g., Living Room Monstera"
        >
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
  </div>
</template>

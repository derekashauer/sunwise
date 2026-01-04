<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useLocationsStore } from '@/stores/locations'
import PlantCard from '@/components/plants/PlantCard.vue'

const router = useRouter()
const plants = usePlantsStore()
const locationsStore = useLocationsStore()

const showLocationsPanel = ref(false)
const editingLocation = ref(null)
const newLocationName = ref('')
const savingLocation = ref(false)
const selectedLocation = ref('all') // 'all' or location name

const windowOrientations = [
  { value: null, label: 'Not set', icon: '?' },
  { value: 'north', label: 'North', icon: 'N' },
  { value: 'south', label: 'South', icon: 'S' },
  { value: 'east', label: 'East', icon: 'E' },
  { value: 'west', label: 'West', icon: 'W' },
  { value: 'none', label: 'No window', icon: '-' }
]

onMounted(() => {
  plants.fetchPlants()
  locationsStore.fetchLocations()
})

function startEditLocation(location) {
  editingLocation.value = {
    id: location.id,
    name: location.name,
    window_orientation: location.window_orientation
  }
}

async function saveLocation() {
  if (!editingLocation.value) return
  savingLocation.value = true
  try {
    await locationsStore.updateLocation(editingLocation.value.id, {
      name: editingLocation.value.name,
      window_orientation: editingLocation.value.window_orientation
    })
    window.$toast?.success('Location updated!')
    editingLocation.value = null
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to update location')
  } finally {
    savingLocation.value = false
  }
}

async function addLocation() {
  if (!newLocationName.value.trim()) return
  try {
    await locationsStore.createLocation(newLocationName.value.trim())
    newLocationName.value = ''
    window.$toast?.success('Location added!')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to add location')
  }
}

async function deleteLocation(id) {
  if (!confirm('Delete this location? Plants will keep their data but lose this location.')) return
  try {
    await locationsStore.deleteLocation(id)
    window.$toast?.success('Location deleted')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to delete location')
  }
}

function getOrientationLabel(value) {
  return windowOrientations.find(o => o.value === value)?.label || 'Not set'
}

// Get unique location names for filter dropdown
const locationOptions = computed(() => {
  const names = new Set()
  for (const plant of plants.plants) {
    names.add(plant.location_name || 'No Location')
  }
  return ['all', ...Array.from(names).sort((a, b) => {
    if (a === 'No Location') return 1
    if (b === 'No Location') return -1
    return a.localeCompare(b)
  })]
})

// Filter plants by selected location
const filteredPlants = computed(() => {
  if (selectedLocation.value === 'all') {
    return plants.plants
  }
  return plants.plants.filter(p => {
    const loc = p.location_name || 'No Location'
    return loc === selectedLocation.value
  })
})

// Group filtered plants by location
const plantsByLocation = computed(() => {
  const groups = {}
  for (const plant of filteredPlants.value) {
    const locationName = plant.location_name || 'No Location'
    if (!groups[locationName]) {
      groups[locationName] = []
    }
    groups[locationName].push(plant)
  }
  // Sort location names, putting "No Location" last
  const sortedLocations = Object.keys(groups).sort((a, b) => {
    if (a === 'No Location') return 1
    if (b === 'No Location') return -1
    return a.localeCompare(b)
  })
  return sortedLocations.map(name => ({ name, plants: groups[name] }))
})
</script>

<template>
  <div class="page-container">
    <header class="flex items-center justify-between mb-4">
      <div>
        <h1 class="page-title mb-0">My Plants</h1>
        <p class="text-gray-500">{{ plants.plantsCount }} plant{{ plants.plantsCount !== 1 ? 's' : '' }}</p>
      </div>
      <div class="flex gap-2">
        <button @click="showLocationsPanel = !showLocationsPanel" class="btn-secondary px-3">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
        </button>
        <button @click="router.push('/plants/add')" class="btn-primary">
          <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add
        </button>
      </div>
    </header>

    <!-- Location filter -->
    <div v-if="plants.plants.length > 0 && locationOptions.length > 2" class="mb-4">
      <div class="flex gap-2 overflow-x-auto pb-2 -mx-4 px-4">
        <button
          v-for="loc in locationOptions"
          :key="loc"
          @click="selectedLocation = loc"
          class="px-3 py-1.5 rounded-full text-sm whitespace-nowrap transition-all"
          :class="selectedLocation === loc
            ? 'bg-plant-500 text-white'
            : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
        >
          {{ loc === 'all' ? 'All Locations' : loc }}
        </button>
      </div>
    </div>

    <!-- Locations Panel -->
    <div v-if="showLocationsPanel" class="mb-6 p-4 bg-white rounded-2xl shadow-sm border border-gray-100">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold text-gray-900">Manage Locations</h2>
        <button @click="showLocationsPanel = false" class="text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Add new location -->
      <div class="flex gap-2 mb-4">
        <input
          v-model="newLocationName"
          type="text"
          class="input flex-1 text-sm"
          placeholder="New location name..."
          @keyup.enter="addLocation"
        >
        <button @click="addLocation" :disabled="!newLocationName.trim()" class="btn-primary text-sm px-3">
          Add
        </button>
      </div>

      <!-- Locations list -->
      <div v-if="locationsStore.locations.length > 0" class="space-y-2">
        <div
          v-for="location in locationsStore.locations"
          :key="location.id"
          class="p-3 bg-gray-50 rounded-xl"
        >
          <template v-if="editingLocation?.id === location.id">
            <!-- Editing mode -->
            <div class="space-y-3">
              <input
                v-model="editingLocation.name"
                type="text"
                class="input text-sm"
                placeholder="Location name"
              >
              <div>
                <label class="block text-xs text-gray-500 mb-1">Window Orientation</label>
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="orient in windowOrientations"
                    :key="orient.value"
                    @click="editingLocation.window_orientation = orient.value"
                    class="px-2 py-1 text-xs rounded-lg border transition-all"
                    :class="editingLocation.window_orientation === orient.value
                      ? 'border-plant-500 bg-plant-50 text-plant-700'
                      : 'border-gray-200 hover:border-gray-300 text-gray-600'"
                  >
                    {{ orient.label }}
                  </button>
                </div>
              </div>
              <div class="flex gap-2">
                <button
                  @click="saveLocation"
                  :disabled="savingLocation"
                  class="btn-primary text-xs px-3 py-1"
                >
                  Save
                </button>
                <button
                  @click="editingLocation = null"
                  class="btn-secondary text-xs px-3 py-1"
                >
                  Cancel
                </button>
              </div>
            </div>
          </template>
          <template v-else>
            <!-- View mode -->
            <div class="flex items-center justify-between">
              <div>
                <span class="font-medium text-gray-900">{{ location.name }}</span>
                <span class="text-xs text-gray-500 ml-2">
                  ({{ location.plant_count || 0 }} plants)
                </span>
                <span v-if="location.window_orientation" class="text-xs text-plant-600 ml-2">
                  {{ getOrientationLabel(location.window_orientation) }}-facing
                </span>
              </div>
              <div class="flex gap-1">
                <button
                  @click="startEditLocation(location)"
                  class="p-1 text-gray-400 hover:text-plant-600"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                </button>
                <button
                  @click="deleteLocation(location.id)"
                  class="p-1 text-gray-400 hover:text-red-600"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>
      <p v-else class="text-sm text-gray-500 text-center py-4">
        No locations yet. Add one to organize your plants!
      </p>
    </div>

    <!-- Loading state -->
    <div v-if="plants.loading" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="plants.plants.length === 0" class="text-center py-12">
      <div class="w-20 h-20 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
        <svg class="w-10 h-10 text-plant-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
        </svg>
      </div>
      <h2 class="text-lg font-semibold text-gray-900 mb-2">No plants yet</h2>
      <p class="text-gray-500 mb-6">Add your first plant to get started</p>
      <button @click="router.push('/plants/add')" class="btn-primary">
        Add Your First Plant
      </button>
    </div>

    <!-- Plants grouped by location -->
    <div v-else class="space-y-6">
      <div v-for="group in plantsByLocation" :key="group.name">
        <!-- Location header -->
        <div class="flex items-center gap-2 mb-3">
          <svg class="w-4 h-4 text-plant-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span class="font-semibold text-gray-700">{{ group.name }}</span>
          <span class="text-sm text-gray-400">({{ group.plants.length }})</span>
        </div>
        <!-- Plants grid for this location -->
        <div class="grid grid-cols-2 gap-4">
          <PlantCard
            v-for="plant in group.plants"
            :key="plant.id"
            :plant="plant"
            @click="router.push(`/plants/${plant.id}`)"
          />
        </div>
      </div>

      <!-- Graveyard link -->
      <router-link
        to="/graveyard"
        class="flex items-center justify-center gap-2 py-3 text-sm text-gray-400 hover:text-gray-600 transition-colors"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
        </svg>
        <span>Plant Graveyard</span>
      </router-link>
    </div>
  </div>
</template>

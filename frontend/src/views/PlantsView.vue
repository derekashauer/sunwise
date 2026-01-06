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
const searchQuery = ref('')

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

// Filter plants by selected location and search query
const filteredPlants = computed(() => {
  let result = plants.plants

  // Filter by location
  if (selectedLocation.value !== 'all') {
    result = result.filter(p => {
      const loc = p.location_name || 'No Location'
      return loc === selectedLocation.value
    })
  }

  // Filter by search query
  if (searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase().trim()
    result = result.filter(p => {
      const name = (p.name || '').toLowerCase()
      const species = (p.species || '').toLowerCase()
      return name.includes(query) || species.includes(query)
    })
  }

  return result
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
        <p class="text-charcoal-400 flex items-center gap-1">
          <img
            src="https://img.icons8.com/doodle/48/potted-plant--v1.png"
            alt=""
            class="w-4 h-4"
          >
          {{ plants.plantsCount }} plant{{ plants.plantsCount !== 1 ? 's' : '' }}
        </p>
      </div>
      <div class="flex gap-2">
        <button @click="showLocationsPanel = !showLocationsPanel" class="btn-secondary px-3">
          <img
            src="https://img.icons8.com/doodle/48/place-marker.png"
            alt="locations"
            class="w-5 h-5"
          >
        </button>
        <button @click="router.push('/plants/add')" class="btn-primary">
          <img
            src="https://img.icons8.com/doodle-line/60/plus.png"
            alt=""
            class="w-5 h-5 mr-1"
          >
          Add
        </button>
      </div>
    </header>

    <!-- Search bar -->
    <div v-if="plants.plants.length > 3" class="mb-4">
      <div class="relative">
        <img
          src="https://img.icons8.com/doodle/48/search.png"
          alt="search"
          class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 opacity-50"
        >
        <input
          v-model="searchQuery"
          type="text"
          class="input pl-10 text-sm"
          placeholder="Search by name or species..."
        >
        <button
          v-if="searchQuery"
          @click="searchQuery = ''"
          class="absolute right-3 top-1/2 -translate-y-1/2 text-charcoal-400 hover:text-charcoal-600"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Location filter -->
    <div v-if="plants.plants.length > 0 && locationOptions.length > 2 && !searchQuery" class="mb-4">
      <div class="flex gap-2 overflow-x-auto pb-2 -mx-4 px-4">
        <button
          v-for="loc in locationOptions"
          :key="loc"
          @click="selectedLocation = loc"
          class="px-3 py-1.5 rounded-full text-sm whitespace-nowrap transition-all font-medium"
          :class="selectedLocation === loc
            ? 'bg-sage-500 text-white shadow-sage'
            : 'bg-cream-200 text-charcoal-500 hover:bg-cream-300'"
        >
          {{ loc === 'all' ? 'All Locations' : loc }}
        </button>
      </div>
    </div>

    <!-- Locations Panel -->
    <div v-if="showLocationsPanel" class="mb-6 card p-4">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-hand text-xl text-charcoal-700 flex items-center gap-2">
          <img
            src="https://img.icons8.com/doodle/48/place-marker.png"
            alt=""
            class="w-6 h-6"
          >
          Manage Locations
        </h2>
        <button @click="showLocationsPanel = false" class="text-charcoal-400 hover:text-charcoal-600 p-1">
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
          class="p-3 bg-cream-100 rounded-xl"
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
                <label class="block text-xs text-charcoal-400 mb-1">Window Orientation</label>
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="orient in windowOrientations"
                    :key="orient.value"
                    @click="editingLocation.window_orientation = orient.value"
                    class="px-2 py-1 text-xs rounded-lg border-2 transition-all"
                    :class="editingLocation.window_orientation === orient.value
                      ? 'border-sage-500 bg-sage-50 text-sage-700'
                      : 'border-cream-300 hover:border-charcoal-200 text-charcoal-500'"
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
                <span class="font-medium text-charcoal-700">{{ location.name }}</span>
                <span class="text-xs text-charcoal-400 ml-2">
                  ({{ location.plant_count || 0 }} plants)
                </span>
                <span v-if="location.window_orientation" class="text-xs text-sage-600 ml-2">
                  {{ getOrientationLabel(location.window_orientation) }}-facing
                </span>
              </div>
              <div class="flex gap-1">
                <button
                  @click="startEditLocation(location)"
                  class="p-1 text-charcoal-400 hover:text-sage-600"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                </button>
                <button
                  @click="deleteLocation(location.id)"
                  class="p-1 text-charcoal-400 hover:text-terracotta-600"
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
      <p v-else class="text-sm text-charcoal-400 text-center py-4">
        No locations yet. Add one to organize your plants!
      </p>
    </div>

    <!-- Loading state -->
    <div v-if="plants.loading" class="flex flex-col items-center justify-center py-12">
      <img
        src="https://img.icons8.com/doodle/96/watering-can.png"
        alt="loading"
        class="w-16 h-16 loading-watering-can"
      >
      <p class="text-charcoal-400 mt-4 font-hand text-xl">Loading your plants...</p>
    </div>

    <!-- Empty state -->
    <div v-else-if="plants.plants.length === 0" class="text-center py-12">
      <div class="w-24 h-24 mx-auto mb-4 bg-sage-100 rounded-3xl flex items-center justify-center shadow-sage">
        <img
          src="https://img.icons8.com/doodle/96/potted-plant--v1.png"
          alt="plants"
          class="w-14 h-14"
        >
      </div>
      <h2 class="font-hand text-2xl text-charcoal-600 mb-2">No plants yet!</h2>
      <p class="text-charcoal-400 mb-6">Add your first plant to get started</p>
      <button @click="router.push('/plants/add')" class="btn-primary">
        <img
          src="https://img.icons8.com/doodle-line/60/plus.png"
          alt=""
          class="w-5 h-5 mr-2"
        >
        Add Your First Plant
      </button>
    </div>

    <!-- No search results -->
    <div v-else-if="plants.plants.length > 0 && filteredPlants.length === 0" class="text-center py-12">
      <div class="w-20 h-20 mx-auto mb-4 bg-cream-200 rounded-3xl flex items-center justify-center">
        <img
          src="https://img.icons8.com/doodle/96/search.png"
          alt="no results"
          class="w-10 h-10 opacity-50"
        >
      </div>
      <h2 class="font-hand text-xl text-charcoal-600 mb-2">No plants found</h2>
      <p class="text-charcoal-400 mb-4">Try a different search term</p>
      <button @click="searchQuery = ''; selectedLocation = 'all'" class="btn-secondary">
        Clear filters
      </button>
    </div>

    <!-- Plants grouped by location -->
    <div v-else class="space-y-6">
      <div v-for="group in plantsByLocation" :key="group.name">
        <!-- Location header -->
        <div class="flex items-center gap-2 mb-3">
          <img
            src="https://img.icons8.com/doodle/48/place-marker.png"
            alt="location"
            class="w-5 h-5"
          >
          <span class="font-semibold text-charcoal-600">{{ group.name }}</span>
          <span class="text-sm text-charcoal-300 bg-cream-200 px-2 py-0.5 rounded-full">{{ group.plants.length }}</span>
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
        class="flex items-center justify-center gap-2 py-3 text-sm text-charcoal-400 hover:text-charcoal-600 transition-colors"
      >
        <img
          src="https://img.icons8.com/doodle/48/poison.png"
          alt=""
          class="w-5 h-5 opacity-60"
        >
        <span>Plant Graveyard</span>
      </router-link>
    </div>
  </div>
</template>

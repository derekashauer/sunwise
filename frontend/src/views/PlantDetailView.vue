<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useTasksStore } from '@/stores/tasks'
import { useApi } from '@/composables/useApi'
import TaskItem from '@/components/tasks/TaskItem.vue'
import PlantChatModal from '@/components/chat/PlantChatModal.vue'
import CareLogModal from '@/components/care/CareLogModal.vue'
import CareLogEntry from '@/components/care/CareLogEntry.vue'
import LoadingOverlay from '@/components/common/LoadingOverlay.vue'

const router = useRouter()
const route = useRoute()
const plants = usePlantsStore()
const tasksStore = useTasksStore()
const api = useApi()

const plant = ref(null)
const photos = ref([])
const tasks = ref([])
const carePlan = ref(null)
const loading = ref(true)
const showPhotoUpload = ref(false)
const uploadingPhoto = ref(false)
const showDeleteConfirm = ref(false)
const showChat = ref(false)
const showHealthPicker = ref(false)
const updatingHealth = ref(false)
const parentPlant = ref(null)
const childPlants = ref([])
const careLog = ref([])
const actionTypes = ref({ preset: [], custom: [] })
const showCareLog = ref(false)
const showCareLogModal = ref(false)
const careLogFilter = ref('')
const showAllCareLog = ref(false)
const showShoppingModal = ref(false)
const shoppingItem = ref('')
const shoppingNotes = ref('')
const savingShoppingItem = ref(false)
const showArchiveModal = ref(false)
const archiveReason = ref('')
const archiving = ref(false)
const showShareModal = ref(false)
const linkCopied = ref(false)

const healthColors = {
  thriving: 'bg-green-100 text-green-700',
  healthy: 'bg-plant-100 text-plant-700',
  struggling: 'bg-yellow-100 text-yellow-700',
  critical: 'bg-red-100 text-red-700',
  unknown: 'bg-gray-100 text-gray-500'
}

onMounted(async () => {
  try {
    const [plantData, photosData, carePlanData, careLogData, actionTypesData] = await Promise.all([
      plants.getPlant(route.params.id),
      plants.getPhotos(route.params.id),
      api.get(`/plants/${route.params.id}/care-plan`),
      api.get(`/plants/${route.params.id}/care-log`),
      api.get('/action-types')
    ])
    plant.value = plantData
    photos.value = photosData
    carePlan.value = carePlanData.care_plan
    tasks.value = carePlanData.tasks || []
    careLog.value = careLogData.care_log || []
    actionTypes.value = actionTypesData

    // Load parent plant if this is a propagation
    if (plantData.parent_plant_id) {
      try {
        parentPlant.value = await plants.getPlant(plantData.parent_plant_id)
      } catch (e) {
        console.error('Failed to load parent plant:', e)
      }
    }

    // Load children (propagations from this plant)
    await plants.fetchPlants()
    childPlants.value = plants.plants.filter(p => p.parent_plant_id === plantData.id)
  } catch (e) {
    window.$toast?.error('Failed to load plant')
    router.back()
  } finally {
    loading.value = false
  }
})

function formatTaskDate(dateStr) {
  const date = new Date(dateStr + 'T00:00:00')
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const diffDays = Math.round((date - today) / (1000 * 60 * 60 * 24))

  const options = { month: 'short', day: 'numeric' }
  const formatted = date.toLocaleDateString('en-US', options)

  if (diffDays === 0) return `${formatted} (today)`
  if (diffDays === 1) return `${formatted} (tomorrow)`
  if (diffDays < 0) return `${formatted} (${Math.abs(diffDays)} days ago)`
  return `${formatted} (${diffDays} days)`
}

function getTaskIcon(taskType) {
  const icons = {
    water: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />',
    fertilize: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />',
    check: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />',
    trim: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z" />',
    repot: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />'
  }
  return icons[taskType] || icons.check
}

async function handlePhotoUpload(event) {
  const file = event.target.files[0]
  if (!file) return

  uploadingPhoto.value = true
  try {
    const formData = new FormData()
    formData.append('image', file)
    const photo = await plants.uploadPhoto(route.params.id, formData)
    photos.value.unshift(photo)
    showPhotoUpload.value = false
    window.$toast?.success('Photo uploaded! AI is analyzing health...')
  } catch (e) {
    window.$toast?.error(e.message)
  } finally {
    uploadingPhoto.value = false
  }
}

async function deletePlant() {
  try {
    await plants.deletePlant(route.params.id)
    window.$toast?.success('Plant deleted')
    router.replace('/plants')
  } catch (e) {
    window.$toast?.error(e.message)
  }
}

const upcomingTasks = computed(() => tasks.value.filter(t => !t.completed_at))

const isArchived = computed(() => !!plant.value?.archived_at)

// Extract care schedule summary from tasks
const careScheduleSummary = computed(() => {
  const schedules = {}
  const taskLabels = {
    water: 'Water',
    fertilize: 'Fertilize',
    check: 'Check',
    mist: 'Mist',
    rotate: 'Rotate',
    trim: 'Trim',
    repot: 'Repot',
    change_water: 'Change water',
    check_roots: 'Check roots',
    pot_up: 'Pot up'
  }

  // Get unique task types with their recurrence
  for (const task of tasks.value) {
    if (!task.recurrence || schedules[task.task_type]) continue
    try {
      const recurrence = typeof task.recurrence === 'string'
        ? JSON.parse(task.recurrence)
        : task.recurrence
      if (recurrence?.interval) {
        schedules[task.task_type] = {
          label: taskLabels[task.task_type] || task.task_type,
          interval: recurrence.interval,
          type: recurrence.type || 'days'
        }
      }
    } catch (e) {
      console.error('Failed to parse recurrence:', e)
    }
  }

  return Object.values(schedules)
})

const filteredCareLog = computed(() => {
  if (!careLogFilter.value) return careLog.value
  return careLog.value.filter(e => e.action === careLogFilter.value)
})

const displayedCareLog = computed(() => {
  const logs = filteredCareLog.value
  return showAllCareLog.value ? logs : logs.slice(0, 5)
})

const allActionsList = computed(() => [
  ...actionTypes.value.preset,
  ...actionTypes.value.custom
])

function handleCareLogged(entry) {
  careLog.value.unshift(entry)
}

async function refreshCareLog() {
  try {
    const careLogData = await api.get(`/plants/${route.params.id}/care-log`)
    careLog.value = careLogData.care_log || []
  } catch (e) {
    console.error('Failed to refresh care log:', e)
  }
}

async function refreshPlant() {
  try {
    const [plantData, carePlanData] = await Promise.all([
      plants.getPlant(route.params.id),
      api.get(`/plants/${route.params.id}/care-plan`)
    ])
    plant.value = plantData
    carePlan.value = carePlanData.care_plan
    tasks.value = carePlanData.tasks || []
  } catch (e) {
    console.error('Failed to refresh plant:', e)
  }
}

async function addShoppingItem() {
  if (!shoppingItem.value.trim()) {
    window.$toast?.error('Please enter an item')
    return
  }

  savingShoppingItem.value = true
  try {
    await api.post('/shopping-list', {
      item: shoppingItem.value.trim(),
      plant_id: plant.value.id,
      notes: shoppingNotes.value.trim() || null
    })
    window.$toast?.success('Added to shopping list')
    showShoppingModal.value = false
    shoppingItem.value = ''
    shoppingNotes.value = ''
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to add item')
  } finally {
    savingShoppingItem.value = false
  }
}

const healthOptions = [
  { value: 'thriving', label: 'Thriving', emoji: '🌟', desc: 'Growing vigorously' },
  { value: 'healthy', label: 'Healthy', emoji: '✅', desc: 'Doing well' },
  { value: 'struggling', label: 'Struggling', emoji: '⚠️', desc: 'Needs attention' },
  { value: 'critical', label: 'Critical', emoji: '🚨', desc: 'Urgent care needed' }
]

async function updateHealthStatus(status) {
  updatingHealth.value = true
  try {
    await plants.updatePlant(route.params.id, { health_status: status })
    plant.value.health_status = status
    showHealthPicker.value = false
    window.$toast?.success('Health status updated')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to update health status')
  } finally {
    updatingHealth.value = false
  }
}

// Loading overlay state
const isProcessing = computed(() => uploadingPhoto.value || updatingHealth.value || archiving.value)
const loadingMessage = computed(() => {
  if (uploadingPhoto.value) return 'Uploading photo...'
  if (updatingHealth.value) return 'Updating health status...'
  if (archiving.value) return 'Archiving plant...'
  return 'Loading...'
})

async function archivePlant() {
  archiving.value = true
  try {
    await api.post(`/plants/${route.params.id}/archive`, {
      death_reason: archiveReason.value.trim() || null
    })
    window.$toast?.success(`${plant.value.name} has been moved to the graveyard`)
    router.replace('/plants')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to archive plant')
  } finally {
    archiving.value = false
    showArchiveModal.value = false
  }
}

function sharePlant() {
  showShareModal.value = true
  linkCopied.value = false
}

// Use api/share/plant URL for social sharing so crawlers get OG meta tags
const shareUrl = computed(() => `${window.location.origin}/api/share/plant/${plant.value?.id}`)
const shareText = computed(() => {
  if (!plant.value) return ''
  return plant.value.species
    ? `Check out my ${plant.value.species}!`
    : `Check out my plant ${plant.value.name}!`
})

async function copyShareLink() {
  try {
    await navigator.clipboard.writeText(shareUrl.value)
    linkCopied.value = true
    setTimeout(() => { linkCopied.value = false }, 2000)
  } catch (e) {
    window.$toast?.error('Failed to copy link')
  }
}

async function nativeShare() {
  if (navigator.share) {
    try {
      await navigator.share({
        title: `${plant.value.name} - Sunwise`,
        text: shareText.value,
        url: shareUrl.value
      })
      showShareModal.value = false
    } catch (e) {
      // User cancelled
    }
  }
}

function shareToTwitter() {
  const text = encodeURIComponent(shareText.value)
  const url = encodeURIComponent(shareUrl.value)
  window.open(`https://twitter.com/intent/tweet?text=${text}&url=${url}`, '_blank', 'width=550,height=420')
}

function shareToFacebook() {
  const url = encodeURIComponent(shareUrl.value)
  window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}`, '_blank', 'width=550,height=420')
}

function shareToWhatsApp() {
  const text = encodeURIComponent(`${shareText.value} ${shareUrl.value}`)
  window.open(`https://wa.me/?text=${text}`, '_blank')
}
</script>

<template>
  <div class="page-container">
    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else-if="plant">
      <!-- Header -->
      <header class="flex items-center gap-4 mb-4">
        <button @click="router.back()" class="btn-ghost p-2 -ml-2">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div class="flex-1 min-w-0">
          <h1 class="text-xl font-bold text-gray-900 truncate">{{ plant.name }}</h1>
          <a
            v-if="plant.species"
            :href="`https://www.google.com/search?q=${encodeURIComponent(plant.species + ' plant')}`"
            target="_blank"
            rel="noopener"
            class="text-plant-600 text-sm hover:underline inline-flex items-center gap-1"
          >
            {{ plant.species }}
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
          </a>
        </div>
        <!-- Chat button (hide for archived) -->
        <button v-if="!isArchived" @click="showChat = true" class="btn-ghost p-2">
          <img
            src="https://img.icons8.com/doodle/48/chat--v1.png"
            alt="chat"
            class="w-6 h-6"
          >
        </button>
        <!-- Share button -->
        <button @click="sharePlant" class="btn-ghost p-2">
          <img
            src="https://img.icons8.com/doodle/48/share--v1.png"
            alt="share"
            class="w-6 h-6"
          >
        </button>
        <!-- Edit button (hide for archived) -->
        <button v-if="!isArchived" @click="router.push(`/plants/${plant.id}/edit`)" class="btn-ghost p-2">
          <img
            src="https://img.icons8.com/doodle/48/edit--v1.png"
            alt="edit"
            class="w-6 h-6"
          >
        </button>
      </header>

      <!-- Main photo -->
      <div class="relative aspect-square rounded-2xl overflow-hidden bg-gray-100 mb-6">
        <img
          v-if="photos.length > 0"
          :src="`/uploads/plants/${photos[0].filename}`"
          :alt="plant.name"
          class="w-full h-full object-cover"
        >
        <div v-else class="w-full h-full flex items-center justify-center">
          <svg class="w-16 h-16 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
          </svg>
        </div>

        <!-- Badges -->
        <div class="absolute top-3 right-3 flex flex-col gap-2 items-end">
          <!-- Archived/Memorial badge -->
          <span
            v-if="isArchived"
            class="px-3 py-1 text-sm font-medium rounded-full bg-charcoal-700 text-white shadow-sm flex items-center gap-1"
          >
            <img
              src="https://img.icons8.com/doodle/48/poison.png"
              alt="memorial"
              class="w-4 h-4 brightness-0 invert"
            >
            In Memory
          </span>
          <!-- Propagation badge -->
          <span
            v-if="plant.is_propagation && !isArchived"
            class="px-3 py-1 text-sm font-medium rounded-full bg-purple-100 text-purple-700 shadow-sm"
          >
            Propagation
          </span>
          <!-- Health badge (clickable to change, hide for archived) -->
          <button
            v-if="!isArchived"
            @click="showHealthPicker = true"
            class="px-3 py-1 text-sm font-medium rounded-full capitalize flex items-center gap-1 shadow-sm"
            :class="healthColors[plant.health_status] || 'bg-gray-100 text-gray-500'"
          >
            {{ plant.health_status || 'Set status' }}
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
        </div>

        <!-- Add photo button (hide for archived) -->
        <button
          v-if="!isArchived"
          @click="showPhotoUpload = true"
          class="absolute bottom-3 right-3 btn-primary px-3 py-2 shadow-lg"
        >
          <img
            src="https://img.icons8.com/doodle/48/camera--v1.png"
            alt="camera"
            class="w-5 h-5 mr-1"
          >
          New Photo
        </button>
      </div>

      <!-- Propagation / Parent / Children links -->
      <div v-if="plant.is_propagation || parentPlant || childPlants.length > 0" class="card p-4 mb-6">
        <!-- Parent plant link -->
        <div v-if="parentPlant" class="flex items-center gap-3 mb-3">
          <span class="text-gray-500 text-sm">Parent:</span>
          <router-link
            :to="`/plants/${parentPlant.id}`"
            class="flex items-center gap-2 text-plant-600 hover:text-plant-700"
          >
            <img
              v-if="parentPlant.thumbnail"
              :src="`/uploads/plants/${parentPlant.thumbnail}`"
              class="w-8 h-8 rounded-full object-cover"
            >
            <div v-else class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
              </svg>
            </div>
            <span class="font-medium">{{ parentPlant.name }}</span>
          </router-link>
        </div>
        <!-- Propagation date -->
        <div v-if="plant.propagation_date" class="text-sm text-gray-500 mb-3">
          Started propagating: {{ new Date(plant.propagation_date).toLocaleDateString() }}
        </div>
        <!-- Child propagations -->
        <div v-if="childPlants.length > 0">
          <span class="text-gray-500 text-sm block mb-2">Propagations from this plant:</span>
          <div class="flex flex-wrap gap-2">
            <router-link
              v-for="child in childPlants"
              :key="child.id"
              :to="`/plants/${child.id}`"
              class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-sm hover:bg-purple-100"
            >
              <span>{{ child.name }}</span>
            </router-link>
          </div>
        </div>
      </div>

      <!-- Plant details -->
      <div class="card p-4 mb-6">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-gray-900">Details</h2>
          <button
            @click="showShoppingModal = true"
            class="text-sm text-sage-600 font-medium flex items-center gap-1 hover:text-sage-700"
          >
            <img
              src="https://img.icons8.com/doodle/48/shopping-cart--v1.png"
              alt="cart"
              class="w-5 h-5"
            >
            Add to List
          </button>
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span class="text-gray-500">Location</span>
            <p class="font-medium">{{ plant.location_name || plant.location || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Pot Size</span>
            <p class="font-medium capitalize">{{ plant.pot_size || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Soil</span>
            <p class="font-medium capitalize">{{ plant.soil_type === 'water' ? 'Water (Propagation)' : plant.soil_type === 'rooting' ? 'Rooting Medium' : plant.soil_type === 'moss' ? 'Moss Ball' : plant.soil_type || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Light</span>
            <p class="font-medium capitalize">{{ plant.light_condition || 'Not set' }}</p>
          </div>
          <div v-if="plant.has_grow_light">
            <span class="text-gray-500">Grow Light</span>
            <p class="font-medium">{{ plant.grow_light_hours ? `${plant.grow_light_hours} hrs/day` : 'Yes' }}</p>
          </div>
        </div>
        <p v-if="plant.notes" class="mt-4 text-sm text-gray-600 border-t pt-4">
          {{ plant.notes }}
        </p>
      </div>

      <!-- Memorial info for archived plants -->
      <div v-if="isArchived" class="card p-4 mb-6 bg-cream-100 border-charcoal-200">
        <div class="flex items-center gap-2 mb-2">
          <img
            src="https://img.icons8.com/doodle/48/poison.png"
            alt="memorial"
            class="w-5 h-5"
          >
          <h2 class="font-semibold text-charcoal-700">In Loving Memory</h2>
        </div>
        <p class="text-sm text-gray-600">
          Archived on {{ new Date(plant.archived_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) }}
        </p>
        <p v-if="plant.death_reason" class="text-sm text-gray-500 mt-2 italic">
          "{{ plant.death_reason }}"
        </p>
      </div>

      <!-- Care Plan Schedule (hide for archived) -->
      <div v-if="!isArchived" class="mb-6">
        <h2 class="font-semibold text-gray-900 mb-3">Care Schedule</h2>

        <!-- Schedule Summary -->
        <div v-if="careScheduleSummary.length > 0" class="flex flex-wrap gap-2 mb-3">
          <span
            v-for="schedule in careScheduleSummary"
            :key="schedule.label"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-plant-50 text-plant-700 rounded-full text-sm border border-plant-100"
          >
            <span class="font-medium">{{ schedule.label }}</span>
            <span class="text-plant-500">every {{ schedule.interval }} {{ schedule.type }}</span>
          </span>
        </div>

        <!-- AI Reasoning (if available) -->
        <div v-if="carePlan?.ai_reasoning" class="bg-cream-50 rounded-xl p-3 mb-3 border border-cream-200">
          <p class="text-sm text-charcoal-600">{{ carePlan.ai_reasoning }}</p>
        </div>

        <div v-if="upcomingTasks.length > 0" class="card divide-y">
          <div
            v-for="task in upcomingTasks"
            :key="task.id"
            class="flex items-center gap-3 p-3"
          >
            <div class="w-8 h-8 rounded-full bg-plant-100 flex items-center justify-center flex-shrink-0">
              <svg class="w-4 h-4 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="getTaskIcon(task.task_type)"></svg>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-medium text-gray-900 capitalize">{{ task.task_type }}</p>
              <p class="text-sm text-gray-500">{{ formatTaskDate(task.due_date) }}</p>
            </div>
            <span
              v-if="task.priority === 'high' || task.priority === 'urgent'"
              class="px-2 py-0.5 text-xs font-medium rounded-full"
              :class="task.priority === 'urgent' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'"
            >
              {{ task.priority }}
            </span>
          </div>
        </div>
        <div v-else class="card p-4 text-center text-gray-500">
          <p>No upcoming tasks scheduled</p>
        </div>
      </div>

      <!-- Care History (show read-only for archived, with Log Care button for active) -->
      <div class="mb-6">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-gray-900">Care History</h2>
          <button
            v-if="!isArchived"
            @click="showCareLogModal = true"
            class="text-sm text-sage-600 font-medium flex items-center gap-1 hover:text-sage-700"
          >
            <img
              src="https://img.icons8.com/doodle/48/add.png"
              alt="add"
              class="w-5 h-5"
            >
            Log Care
          </button>
        </div>

        <div v-if="careLog.length > 0" class="card">
          <!-- Filter dropdown -->
          <div v-if="careLog.length > 3" class="p-3 border-b">
            <select
              v-model="careLogFilter"
              class="input text-sm py-1.5"
            >
              <option value="">All Activities</option>
              <option v-for="action in allActionsList" :key="action.value" :value="action.value">
                {{ action.icon }} {{ action.label }}
              </option>
            </select>
          </div>

          <!-- Log entries -->
          <div class="divide-y">
            <CareLogEntry
              v-for="entry in displayedCareLog"
              :key="entry.id"
              :entry="entry"
              :action-types="actionTypes"
              class="px-4"
            />
          </div>

          <!-- Show more/less -->
          <div v-if="filteredCareLog.length > 5" class="p-3 border-t">
            <button
              @click="showAllCareLog = !showAllCareLog"
              class="text-sm text-plant-600 font-medium w-full text-center hover:text-plant-700"
            >
              {{ showAllCareLog ? 'Show Less' : `View All (${filteredCareLog.length})` }}
            </button>
          </div>
        </div>

        <div v-else class="card p-4 text-center text-gray-500">
          <p class="mb-2">No care activities logged yet</p>
          <button
            @click="showCareLogModal = true"
            class="text-sm text-plant-600 font-medium hover:text-plant-700"
          >
            Log your first care activity
          </button>
        </div>
      </div>

      <!-- Photo gallery -->
      <div v-if="photos.length > 1" class="mb-6">
        <h2 class="font-semibold text-gray-900 mb-3">Photo History</h2>
        <div class="flex gap-2 overflow-x-auto pb-2">
          <div
            v-for="photo in photos"
            :key="photo.id"
            class="w-20 h-20 flex-shrink-0 rounded-xl overflow-hidden"
          >
            <img :src="`/uploads/plants/${photo.thumbnail || photo.filename}`" class="w-full h-full object-cover">
          </div>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="mt-8 pt-6 border-t space-y-3">
        <!-- Archive button (for active plants) -->
        <button
          v-if="!isArchived"
          @click="showArchiveModal = true"
          class="w-full text-charcoal-500 text-sm font-medium py-3 flex items-center justify-center gap-2 hover:text-charcoal-700"
        >
          <img
            src="https://img.icons8.com/doodle/48/poison.png"
            alt="graveyard"
            class="w-5 h-5"
          >
          Send to Graveyard
        </button>
        <!-- Delete button (permanent delete, for both active and archived) -->
        <button
          @click="showDeleteConfirm = true"
          class="w-full text-red-600 text-sm font-medium py-3"
        >
          {{ isArchived ? 'Delete Permanently' : 'Delete Plant' }}
        </button>
      </div>
    </template>

    <!-- Photo upload modal -->
    <div v-if="showPhotoUpload" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
      <div class="bg-white rounded-t-3xl w-full max-w-lg p-6 safe-bottom">
        <h3 class="text-lg font-semibold mb-4">Add New Photo</h3>
        <p class="text-gray-500 text-sm mb-4">Take a photo to update your plant's health status</p>

        <input
          type="file"
          accept="image/*"
          capture="environment"
          @change="handlePhotoUpload"
          class="hidden"
          id="photo-upload"
        >

        <label
          for="photo-upload"
          class="btn-primary w-full mb-3 cursor-pointer"
          :class="{ 'opacity-50': uploadingPhoto }"
        >
          <span v-if="uploadingPhoto" class="flex items-center justify-center gap-2">
            <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
            Uploading...
          </span>
          <span v-else>Take Photo</span>
        </label>

        <button @click="showPhotoUpload = false" class="btn-secondary w-full">
          Cancel
        </button>
      </div>
    </div>

    <!-- Delete confirm modal -->
    <div v-if="showDeleteConfirm" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-6">
      <div class="bg-white rounded-2xl w-full max-w-sm p-6">
        <h3 class="text-lg font-semibold mb-2">Delete Plant?</h3>
        <p class="text-gray-500 text-sm mb-6">This will permanently delete {{ plant?.name }} and all its care history.</p>

        <div class="flex gap-3">
          <button @click="showDeleteConfirm = false" class="btn-secondary flex-1">
            Cancel
          </button>
          <button @click="deletePlant" class="btn flex-1 bg-red-500 text-white hover:bg-red-600">
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Archive modal (send to graveyard) -->
    <div v-if="showArchiveModal" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
      <div class="bg-white rounded-t-3xl w-full max-w-lg p-6 safe-bottom">
        <div class="flex items-center gap-2 mb-2">
          <img
            src="https://img.icons8.com/doodle/48/poison.png"
            alt="graveyard"
            class="w-6 h-6"
          >
          <h3 class="text-lg font-semibold text-charcoal-700">Send to Graveyard</h3>
        </div>
        <p class="text-gray-500 text-sm mb-4">
          {{ plant?.name }} will be archived as a memorial. You can still view its photos and care history.
        </p>

        <div class="mb-4">
          <label for="death-reason" class="block text-sm font-medium text-gray-700 mb-1">
            What happened? (optional)
          </label>
          <input
            id="death-reason"
            v-model="archiveReason"
            type="text"
            class="input"
            placeholder="e.g., Root rot, Overwatered, Cat ate it..."
          >
        </div>

        <div class="flex gap-3">
          <button @click="showArchiveModal = false" class="btn-secondary flex-1">
            Cancel
          </button>
          <button
            @click="archivePlant"
            :disabled="archiving"
            class="btn flex-1 bg-gray-700 text-white hover:bg-gray-800"
          >
            <span v-if="archiving" class="flex items-center justify-center gap-2">
              <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Archiving...
            </span>
            <span v-else>Archive Plant</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Chat modal -->
    <PlantChatModal
      v-if="plant"
      :plant="plant"
      :visible="showChat"
      @close="showChat = false"
      @plant-updated="refreshPlant"
    />

    <!-- Health status picker modal -->
    <div v-if="showHealthPicker" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
      <div class="bg-white rounded-t-3xl w-full max-w-lg p-6 safe-bottom">
        <h3 class="text-lg font-semibold mb-4">Update Health Status</h3>
        <p class="text-gray-500 text-sm mb-4">How is {{ plant?.name }} doing?</p>

        <div class="space-y-2 mb-4">
          <button
            v-for="option in healthOptions"
            :key="option.value"
            @click="updateHealthStatus(option.value)"
            :disabled="updatingHealth"
            class="w-full flex items-center gap-3 p-3 rounded-xl border-2 transition-all text-left"
            :class="plant?.health_status === option.value
              ? 'border-plant-500 bg-plant-50'
              : 'border-gray-200 hover:border-gray-300'"
          >
            <span class="text-2xl">{{ option.emoji }}</span>
            <div class="flex-1">
              <p class="font-medium text-gray-900">{{ option.label }}</p>
              <p class="text-sm text-gray-500">{{ option.desc }}</p>
            </div>
            <svg v-if="plant?.health_status === option.value" class="w-5 h-5 text-plant-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>

        <button @click="showHealthPicker = false" class="btn-secondary w-full">
          Cancel
        </button>
      </div>
    </div>

    <!-- Care Log Modal -->
    <CareLogModal
      v-if="plant"
      :plant-id="plant.id"
      :visible="showCareLogModal"
      @close="showCareLogModal = false"
      @logged="handleCareLogged"
    />

    <!-- Shopping List Modal -->
    <div v-if="showShoppingModal" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
      <div class="bg-white rounded-t-3xl w-full max-w-lg p-6 safe-bottom">
        <h3 class="text-lg font-semibold mb-2">Add to Shopping List</h3>
        <p class="text-sm text-gray-500 mb-4">Add an item for {{ plant?.name }}</p>

        <div class="space-y-4">
          <div>
            <label for="shopping-item" class="block text-sm font-medium text-gray-700 mb-1">Item *</label>
            <input
              id="shopping-item"
              v-model="shoppingItem"
              type="text"
              class="input"
              placeholder="e.g., New pot, Fertilizer, Moss pole..."
            >
          </div>

          <div>
            <label for="shopping-notes" class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
            <input
              id="shopping-notes"
              v-model="shoppingNotes"
              type="text"
              class="input"
              placeholder="Size, brand, etc..."
            >
          </div>
        </div>

        <div class="flex gap-3 mt-6">
          <button @click="showShoppingModal = false" class="btn-secondary flex-1">Cancel</button>
          <button @click="addShoppingItem" :disabled="savingShoppingItem" class="btn-primary flex-1">
            <span v-if="savingShoppingItem" class="flex items-center justify-center gap-2">
              <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Adding...
            </span>
            <span v-else>Add to List</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Share Modal -->
    <div v-if="showShareModal" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
      <div class="bg-white rounded-t-3xl w-full max-w-lg p-6 safe-bottom">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold">Share {{ plant?.name }}</h3>
          <button @click="showShareModal = false" class="p-1 text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Plant preview -->
        <div class="flex items-center gap-3 p-3 bg-cream-100 rounded-xl mb-4">
          <img
            v-if="photos.length > 0"
            :src="`/uploads/plants/${photos[0].thumbnail || photos[0].filename}`"
            class="w-12 h-12 rounded-lg object-cover"
          >
          <div v-else class="w-12 h-12 rounded-lg bg-gray-200 flex items-center justify-center">
            <img src="https://img.icons8.com/doodle/48/potted-plant--v1.png" class="w-6 h-6">
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-medium text-charcoal-700 truncate">{{ plant?.name }}</p>
            <p v-if="plant?.species" class="text-sm text-charcoal-400 truncate">{{ plant.species }}</p>
          </div>
        </div>

        <!-- Copy link -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Share Link</label>
          <div class="flex gap-2">
            <input
              :value="shareUrl"
              readonly
              class="input text-sm flex-1 font-mono text-xs bg-gray-50"
              @click="$event.target.select()"
            >
            <button
              @click="copyShareLink"
              class="btn-primary px-4 flex items-center gap-1.5"
              :class="{ 'bg-sage-600': linkCopied }"
            >
              <svg v-if="linkCopied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <img v-else src="https://img.icons8.com/doodle-line/48/copy.png" class="w-4 h-4 brightness-0 invert">
              {{ linkCopied ? 'Copied!' : 'Copy' }}
            </button>
          </div>
        </div>

        <!-- Social share buttons -->
        <div class="space-y-2">
          <p class="text-sm font-medium text-gray-700">Share on</p>
          <div class="grid grid-cols-3 gap-2">
            <button
              @click="shareToTwitter"
              class="flex flex-col items-center gap-1 p-3 rounded-xl bg-[#1DA1F2]/10 hover:bg-[#1DA1F2]/20 transition-colors"
            >
              <svg class="w-6 h-6 text-[#1DA1F2]" fill="currentColor" viewBox="0 0 24 24">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
              </svg>
              <span class="text-xs text-gray-600">X</span>
            </button>
            <button
              @click="shareToFacebook"
              class="flex flex-col items-center gap-1 p-3 rounded-xl bg-[#1877F2]/10 hover:bg-[#1877F2]/20 transition-colors"
            >
              <svg class="w-6 h-6 text-[#1877F2]" fill="currentColor" viewBox="0 0 24 24">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
              </svg>
              <span class="text-xs text-gray-600">Facebook</span>
            </button>
            <button
              @click="shareToWhatsApp"
              class="flex flex-col items-center gap-1 p-3 rounded-xl bg-[#25D366]/10 hover:bg-[#25D366]/20 transition-colors"
            >
              <svg class="w-6 h-6 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
              </svg>
              <span class="text-xs text-gray-600">WhatsApp</span>
            </button>
          </div>
        </div>

        <!-- Native share button (mobile) -->
        <button
          v-if="navigator.share"
          @click="nativeShare"
          class="btn-secondary w-full mt-4 flex items-center justify-center gap-2"
        >
          <img src="https://img.icons8.com/doodle/48/share--v1.png" class="w-5 h-5">
          More Options
        </button>
      </div>
    </div>

    <!-- Loading overlay -->
    <LoadingOverlay :visible="isProcessing" :message="loadingMessage" />
  </div>
</template>

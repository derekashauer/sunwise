<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useApi } from '@/composables/useApi'
import TaskItem from '@/components/tasks/TaskItem.vue'
import PlantChatModal from '@/components/chat/PlantChatModal.vue'
import CareLogModal from '@/components/care/CareLogModal.vue'
import CareLogEntry from '@/components/care/CareLogEntry.vue'
import LoadingOverlay from '@/components/common/LoadingOverlay.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import ImageLightbox from '@/components/common/ImageLightbox.vue'
import { getTaskLabel } from '@/constants/taskTypes'
import { parseLocalDate, parseUtc, daysFromToday, formatShortDate } from '@/utils/dates'

const router = useRouter()
const route = useRoute()
const plants = usePlantsStore()
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
const rebuildingPlan = ref(false)
const showRebuildConfirm = ref(false)
const showShareModal = ref(false)
const linkCopied = ref(false)
const lightboxPhoto = ref(null)

const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function'

const healthColors = {
  thriving: 'bg-green-100 text-green-800',
  healthy: 'bg-plant-100 text-plant-800',
  struggling: 'bg-yellow-100 text-yellow-900',
  critical: 'bg-red-100 text-red-800',
  unknown: 'bg-gray-100 text-gray-700'
}

// Load everything for the plant in the URL. Runs again when the id changes,
// since following a parent or cutting link reuses this same page.
async function loadPlant(id) {
  loading.value = true
  showRebuildConfirm.value = false
  parentPlant.value = null
  childPlants.value = []
  try {
    const [plantData, photosData, carePlanData, careLogData, actionTypesData] = await Promise.all([
      plants.getPlant(id),
      plants.getPhotos(id),
      api.get(`/plants/${id}/care-plan`),
      api.get(`/plants/${id}/care-log`),
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
    if (!plants.plants.length) {
      await plants.fetchPlants()
    }
    childPlants.value = plants.plants.filter(p => Number(p.parent_plant_id) === Number(plantData.id))
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't load this plant.")
    router.back()
  } finally {
    loading.value = false
  }
}

watch(() => route.params.id, (id) => {
  if (id && route.name === 'plant-detail') {
    loadPlant(id)
  }
}, { immediate: true })

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
    window.$toast?.success('Photo uploaded. Checking its health now.')
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

const upcomingTasks = computed(() =>
  tasks.value
    .filter(t => !t.completed_at && !t.skipped_at)
    .sort((a, b) => (a.due_date || '').localeCompare(b.due_date || ''))
)

const isArchived = computed(() => !!plant.value?.archived_at)

function describeDue(dueDateStr) {
  const dueDate = parseLocalDate(dueDateStr)
  if (!dueDate) return { text: null, overdue: false }
  const diffDays = daysFromToday(dueDate)
  if (diffDays === 0) return { text: 'Today', overdue: false }
  if (diffDays === 1) return { text: 'Tomorrow', overdue: false }
  if (diffDays < 0) return { text: `${Math.abs(diffDays)}d late`, overdue: true }
  if (diffDays <= 7) return { text: `In ${diffDays}d`, overdue: false }
  return { text: formatShortDate(dueDate), overdue: false }
}

// One line per task type: how often, and when it's next due. Uses the
// earliest pending task of each type, not just the first one in the list.
const careScheduleSummary = computed(() => {
  const schedules = {}
  for (const task of upcomingTasks.value) {
    if (schedules[task.task_type]) continue
    let recurrence = null
    try {
      recurrence = task.recurrence
        ? (typeof task.recurrence === 'string' ? JSON.parse(task.recurrence) : task.recurrence)
        : null
    } catch (e) {
      recurrence = null
    }
    const due = describeDue(task.due_date)
    schedules[task.task_type] = {
      label: getTaskLabel(task.task_type),
      interval: recurrence?.interval || null,
      type: recurrence?.type || 'days',
      nextDue: due.text,
      isOverdue: due.overdue
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

function photoDate(photo) {
  const date = parseUtc(photo.uploaded_at)
  return date ? date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : ''
}

function photoAlt(photo) {
  const date = photoDate(photo)
  return date ? `${plant.value?.name} on ${date}` : `${plant.value?.name}`
}

const propagationStarted = computed(() => {
  const date = parseLocalDate(plant.value?.propagation_date)
  return date ? date.toLocaleDateString() : ''
})

const archivedOn = computed(() => {
  const date = parseUtc(plant.value?.archived_at)
  return date ? date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : ''
})

async function handleCareLogged(entry, meta = {}) {
  if (entry) careLog.value.unshift(entry)
  // Logging care can close a matching task, so reload the schedule.
  if (meta.completedTaskId) {
    await refreshPlant()
  }
}

function handleTaskChanged({ id, changes }) {
  const index = tasks.value.findIndex(t => t.id === id)
  if (index !== -1) {
    tasks.value[index] = { ...tasks.value[index], ...changes }
  }
  // A completion or undo also changes the care history and next task.
  if ('completed_at' in changes) {
    refreshCareLog()
    refreshTasks()
  }
}

function handleTaskRemoved(id) {
  tasks.value = tasks.value.filter(t => t.id !== id)
  refreshTasks()
}

async function refreshCareLog() {
  try {
    const careLogData = await api.get(`/plants/${route.params.id}/care-log`)
    careLog.value = careLogData.care_log || []
  } catch (e) {
    console.error('Failed to refresh care log:', e)
  }
}

async function refreshTasks() {
  try {
    const carePlanData = await api.get(`/plants/${route.params.id}/care-plan`)
    carePlan.value = carePlanData.care_plan
    tasks.value = carePlanData.tasks || []
  } catch (e) {
    console.error('Failed to refresh tasks:', e)
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
    window.$toast?.error('Enter an item first')
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
    window.$toast?.error(e.message || "Couldn't add that item.")
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
    window.$toast?.error(e.message || "Couldn't update the health status.")
  } finally {
    updatingHealth.value = false
  }
}

async function rebuildCarePlan() {
  showRebuildConfirm.value = false
  rebuildingPlan.value = true
  try {
    await api.post(`/plants/${route.params.id}/care-plan/regenerate`, null, { timeout: 90000 })
    window.$toast?.success('Care plan rebuilt')
    await refreshPlant()
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't rebuild the care plan.")
  } finally {
    rebuildingPlan.value = false
  }
}

// Loading overlay state
const isProcessing = computed(() => uploadingPhoto.value || updatingHealth.value || archiving.value || rebuildingPlan.value)
const loadingMessage = computed(() => {
  if (uploadingPhoto.value) return 'Uploading photo...'
  if (updatingHealth.value) return 'Updating health status...'
  if (archiving.value) return 'Archiving plant...'
  if (rebuildingPlan.value) return 'Rebuilding the care plan...'
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
    window.$toast?.error(e.message || "Couldn't archive this plant.")
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
    window.$toast?.error("Couldn't copy the link. Select it and copy it instead.")
  }
}

async function nativeShare() {
  if (!canNativeShare) return
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
    <div v-if="loading" class="flex justify-center py-12" role="status">
      <div class="w-8 h-8 border-2 border-plant-600 border-t-transparent rounded-full animate-spin" aria-hidden="true"></div>
      <span class="sr-only">Loading plant</span>
    </div>

    <template v-else-if="plant">
      <!-- Header -->
      <header class="flex items-center gap-2 mb-4">
        <button type="button" @click="router.back()" class="btn-ghost p-2 -ml-2" aria-label="Go back">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <div class="flex-1 min-w-0">
          <h1 class="text-xl font-bold text-charcoal-800 truncate">{{ plant.name }}</h1>
          <a
            v-if="plant.species"
            :href="`https://www.google.com/search?q=${encodeURIComponent(plant.species + ' plant')}`"
            target="_blank"
            rel="noopener"
            class="text-plant-700 text-sm hover:underline inline-flex items-center gap-1"
          >
            {{ plant.species }}
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            <span class="sr-only">(search the web)</span>
          </a>
        </div>
        <button v-if="!isArchived" type="button" @click="showChat = true" class="btn-ghost p-2" :aria-label="`Chat about ${plant.name}`">
          <img src="https://img.icons8.com/doodle/48/chat--v1.png" alt="" class="w-6 h-6">
        </button>
        <button type="button" @click="sharePlant" class="btn-ghost p-2" :aria-label="`Share ${plant.name}`">
          <img src="https://img.icons8.com/doodle/48/share--v1.png" alt="" class="w-6 h-6">
        </button>
        <button v-if="!isArchived" type="button" @click="router.push(`/plants/${plant.id}/edit`)" class="btn-ghost p-2" :aria-label="`Edit ${plant.name}`">
          <img src="https://img.icons8.com/doodle/48/edit--v1.png" alt="" class="w-6 h-6">
        </button>
      </header>

      <!-- Main photo -->
      <div class="relative aspect-square rounded-2xl overflow-hidden bg-gray-100 mb-6">
        <button
          v-if="photos.length > 0"
          type="button"
          class="w-full h-full block"
          @click="lightboxPhoto = photos[0]"
          :aria-label="`View larger photo of ${plant.name}`"
        >
          <img
            :src="`/uploads/plants/${photos[0].filename}`"
            :alt="photoAlt(photos[0])"
            class="w-full h-full object-cover"
          >
        </button>
        <div v-else class="w-full h-full flex items-center justify-center">
          <svg class="w-16 h-16 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
          </svg>
        </div>

        <!-- Badges -->
        <div class="absolute top-3 right-3 flex flex-col gap-2 items-end">
          <span
            v-if="isArchived"
            class="px-3 py-1 text-sm font-medium rounded-full bg-charcoal-700 text-white shadow-sm flex items-center gap-1"
          >
            <img src="https://img.icons8.com/doodle/48/poison.png" alt="" class="w-4 h-4 brightness-0 invert">
            In Memory
          </span>
          <span
            v-if="plant.is_propagation && !isArchived"
            class="px-3 py-1 text-sm font-medium rounded-full bg-purple-100 text-purple-800 shadow-sm"
          >
            Propagation
          </span>
          <button
            v-if="!isArchived"
            type="button"
            @click="showHealthPicker = true"
            class="px-3 py-1 text-sm font-medium rounded-full capitalize flex items-center gap-1 shadow-sm"
            :class="healthColors[plant.health_status] || 'bg-gray-100 text-gray-700'"
            :aria-label="`Health: ${plant.health_status || 'not set'}. Change health status`"
          >
            {{ plant.health_status || 'Set status' }}
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
        </div>

        <button
          v-if="!isArchived"
          type="button"
          @click="showPhotoUpload = true"
          class="absolute bottom-3 right-3 btn-primary px-3 py-2 shadow-lg"
        >
          <img src="https://img.icons8.com/doodle/48/camera--v1.png" alt="" class="w-5 h-5 mr-1">
          New Photo
        </button>
      </div>

      <!-- Propagation / Parent / Children links -->
      <div v-if="plant.is_propagation || parentPlant || childPlants.length > 0" class="card p-4 mb-6">
        <div v-if="parentPlant" class="flex items-center gap-3 mb-3">
          <span class="text-charcoal-500 text-sm">Parent:</span>
          <router-link
            :to="`/plants/${parentPlant.id}`"
            class="flex items-center gap-2 text-plant-700 hover:text-plant-800"
          >
            <img
              v-if="parentPlant.thumbnail"
              :src="`/uploads/plants/${parentPlant.thumbnail}`"
              alt=""
              class="w-8 h-8 rounded-full object-cover"
            >
            <div v-else class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
              <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
              </svg>
            </div>
            <span class="font-medium">{{ parentPlant.name }}</span>
          </router-link>
        </div>
        <div v-if="propagationStarted" class="text-sm text-charcoal-500 mb-3">
          Started propagating: {{ propagationStarted }}
        </div>
        <div v-if="childPlants.length > 0">
          <span class="text-charcoal-500 text-sm block mb-2">Propagations from this plant:</span>
          <div class="flex flex-wrap gap-2">
            <router-link
              v-for="child in childPlants"
              :key="child.id"
              :to="`/plants/${child.id}`"
              class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-800 rounded-full text-sm hover:bg-purple-100"
            >
              <span>{{ child.name }}</span>
            </router-link>
          </div>
        </div>
      </div>

      <!-- Plant details -->
      <div class="card p-4 mb-6">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-charcoal-800">Details</h2>
          <button
            type="button"
            @click="showShoppingModal = true"
            class="text-sm text-sage-700 font-medium flex items-center gap-1 hover:text-sage-800"
          >
            <img src="https://img.icons8.com/doodle/48/shopping-cart--v1.png" alt="" class="w-5 h-5">
            Add to List
          </button>
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span class="text-charcoal-500">Location</span>
            <p class="font-medium">{{ plant.location_name || plant.location || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-charcoal-500">Pot Size</span>
            <p class="font-medium capitalize">{{ plant.pot_size || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-charcoal-500">Soil</span>
            <p class="font-medium capitalize">{{ plant.soil_type === 'water' ? 'Water (Propagation)' : plant.soil_type === 'rooting' ? 'Rooting Medium' : plant.soil_type === 'moss' ? 'Moss Ball' : plant.soil_type || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-charcoal-500">Light</span>
            <p class="font-medium capitalize">{{ plant.light_condition || 'Not set' }}</p>
          </div>
          <div v-if="plant.has_grow_light">
            <span class="text-charcoal-500">Grow Light</span>
            <p class="font-medium">{{ plant.grow_light_hours ? `${plant.grow_light_hours} hrs/day` : 'Yes' }}</p>
          </div>
        </div>
        <p v-if="plant.notes" class="mt-4 text-sm text-charcoal-600 border-t pt-4">
          {{ plant.notes }}
        </p>
      </div>

      <!-- Memorial info for archived plants -->
      <div v-if="isArchived" class="card p-4 mb-6 bg-cream-100 border-charcoal-200">
        <div class="flex items-center gap-2 mb-2">
          <img src="https://img.icons8.com/doodle/48/poison.png" alt="" class="w-5 h-5">
          <h2 class="font-semibold text-charcoal-700">In Loving Memory</h2>
        </div>
        <p v-if="archivedOn" class="text-sm text-charcoal-600">
          Archived on {{ archivedOn }}
        </p>
        <p v-if="plant.death_reason" class="text-sm text-charcoal-500 mt-2 italic">
          "{{ plant.death_reason }}"
        </p>
      </div>

      <!-- Care schedule (hidden for archived plants) -->
      <section v-if="!isArchived" class="mb-6" aria-labelledby="care-schedule-title">
        <div class="flex items-center justify-between mb-3">
          <h2 id="care-schedule-title" class="font-semibold text-charcoal-800">Care Schedule</h2>
          <button
            type="button"
            @click="showRebuildConfirm = !showRebuildConfirm"
            :disabled="rebuildingPlan"
            :aria-expanded="showRebuildConfirm"
            class="text-sm text-sage-700 font-medium flex items-center gap-1 hover:text-sage-800 py-1"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Rebuild Plan
          </button>
        </div>

        <!-- Rebuild confirmation -->
        <div v-if="showRebuildConfirm" class="mb-3 rounded-xl border-2 border-sunny-300 bg-sunny-50 p-3" role="alert">
          <p class="text-sm text-charcoal-700 font-semibold">Rebuild the care plan for {{ plant.name }}?</p>
          <p class="text-sm text-charcoal-600 mt-1">
            The AI makes a new schedule from this plant's details and history. Pending tasks are replaced.
          </p>
          <div class="flex gap-2 mt-3">
            <button type="button" @click="showRebuildConfirm = false" class="btn-secondary flex-1 py-2 text-sm">Cancel</button>
            <button type="button" @click="rebuildCarePlan" class="btn-primary flex-1 py-2 text-sm">Rebuild</button>
          </div>
        </div>

        <!-- Schedule summary -->
        <div v-if="careScheduleSummary.length > 0" class="space-y-2 mb-3">
          <div
            v-for="schedule in careScheduleSummary"
            :key="schedule.label"
            class="flex items-center justify-between px-3 py-2 bg-plant-50 rounded-xl border border-plant-100"
          >
            <div class="flex items-center gap-2">
              <span class="font-medium text-plant-800">{{ schedule.label }}</span>
              <span class="text-plant-700 text-sm">
                {{ schedule.interval ? `every ${schedule.interval} ${schedule.type}` : 'one time' }}
              </span>
            </div>
            <span
              v-if="schedule.nextDue"
              class="text-xs px-2 py-1 rounded-full"
              :class="schedule.isOverdue ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700'"
            >
              {{ schedule.nextDue }}
            </span>
          </div>
        </div>

        <div v-else-if="tasks.length === 0" class="bg-amber-50 rounded-xl p-3 mb-3 border border-amber-200">
          <p class="text-sm text-amber-900">No care schedule yet. This happens right after a plant is added, or when the plan needs to be rebuilt.</p>
        </div>

        <div v-if="carePlan?.ai_reasoning" class="bg-cream-50 rounded-xl p-3 mb-3 border border-cream-200">
          <p class="text-xs font-semibold text-charcoal-500 mb-1">Why this plan:</p>
          <p class="text-sm text-charcoal-600">{{ carePlan.ai_reasoning }}</p>
        </div>

        <!-- Upcoming tasks, which can be done right here -->
        <div v-if="upcomingTasks.length > 0" class="space-y-2">
          <TaskItem
            v-for="task in upcomingTasks"
            :key="task.id"
            :task="task"
            :plant="plant"
            @changed="handleTaskChanged"
            @removed="handleTaskRemoved"
          />
        </div>
        <div v-else class="card p-4 text-center text-charcoal-500">
          <p>No upcoming tasks scheduled</p>
        </div>
      </section>

      <!-- Care history -->
      <section class="mb-6" aria-labelledby="care-history-title">
        <div class="flex items-center justify-between mb-3">
          <h2 id="care-history-title" class="font-semibold text-charcoal-800">Care History</h2>
          <button
            v-if="!isArchived"
            type="button"
            @click="showCareLogModal = true"
            class="text-sm text-sage-700 font-medium flex items-center gap-1 hover:text-sage-800 py-1"
          >
            <img src="https://img.icons8.com/doodle/48/add.png" alt="" class="w-5 h-5">
            Log Care
          </button>
        </div>

        <div v-if="careLog.length > 0" class="card">
          <div v-if="careLog.length > 3" class="p-3 border-b">
            <label for="care-log-filter" class="sr-only">Filter care history</label>
            <select
              id="care-log-filter"
              v-model="careLogFilter"
              class="input text-sm py-1.5"
            >
              <option value="">All Activities</option>
              <option v-for="action in allActionsList" :key="action.value" :value="action.value">
                {{ action.icon }} {{ action.label }}
              </option>
            </select>
          </div>

          <div class="divide-y">
            <CareLogEntry
              v-for="entry in displayedCareLog"
              :key="entry.id"
              :entry="entry"
              :action-types="actionTypes"
              class="px-4"
            />
          </div>

          <div v-if="filteredCareLog.length > 5" class="p-3 border-t">
            <button
              type="button"
              @click="showAllCareLog = !showAllCareLog"
              class="text-sm text-plant-700 font-medium w-full text-center hover:text-plant-800 py-1"
            >
              {{ showAllCareLog ? 'Show Less' : `View All (${filteredCareLog.length})` }}
            </button>
          </div>
        </div>

        <div v-else class="card p-4 text-center text-charcoal-500">
          <p class="mb-2">No care activities logged yet</p>
          <button
            v-if="!isArchived"
            type="button"
            @click="showCareLogModal = true"
            class="text-sm text-plant-700 font-medium hover:text-plant-800"
          >
            Log your first care activity
          </button>
        </div>
      </section>

      <!-- Photo timeline -->
      <section v-if="photos.length > 1" class="mb-6" aria-labelledby="photo-history-title">
        <h2 id="photo-history-title" class="font-semibold text-charcoal-800 mb-3">Photo History</h2>
        <ul class="flex gap-3 overflow-x-auto pb-2">
          <li v-for="photo in photos" :key="photo.id" class="flex-shrink-0 w-24">
            <button
              type="button"
              class="w-24 h-24 rounded-xl overflow-hidden block border-2 border-transparent hover:border-sage-400 focus:outline-none focus-visible:border-sage-600"
              @click="lightboxPhoto = photo"
              :aria-label="`View photo from ${photoDate(photo) || 'an earlier date'}`"
            >
              <img
                :src="`/uploads/plants/${photo.thumbnail || photo.filename}`"
                :alt="photoAlt(photo)"
                loading="lazy"
                class="w-full h-full object-cover"
              >
            </button>
            <p class="text-xs text-charcoal-500 mt-1 text-center">{{ photoDate(photo) }}</p>
            <p v-if="photo.health_assessment" class="text-xs text-charcoal-600 text-center capitalize">{{ photo.health_assessment }}</p>
          </li>
        </ul>
      </section>

      <!-- Action buttons -->
      <div class="mt-8 pt-6 border-t space-y-3">
        <button
          v-if="!isArchived"
          type="button"
          @click="showArchiveModal = true"
          class="w-full text-charcoal-600 text-sm font-medium py-3 flex items-center justify-center gap-2 hover:text-charcoal-800"
        >
          <img src="https://img.icons8.com/doodle/48/poison.png" alt="" class="w-5 h-5">
          Send to Graveyard
        </button>
        <button
          type="button"
          @click="showDeleteConfirm = true"
          class="w-full text-red-700 text-sm font-medium py-3"
        >
          {{ isArchived ? 'Delete Permanently' : 'Delete Plant' }}
        </button>
      </div>
    </template>

    <!-- Photo lightbox -->
    <ImageLightbox
      :visible="!!lightboxPhoto"
      :src="lightboxPhoto ? `/uploads/plants/${lightboxPhoto.filename}` : ''"
      :alt="lightboxPhoto ? photoAlt(lightboxPhoto) : ''"
      :caption="lightboxPhoto ? photoDate(lightboxPhoto) : ''"
      @close="lightboxPhoto = null"
    />

    <!-- Photo upload -->
    <BaseModal
      :visible="showPhotoUpload"
      title="Add a new photo"
      subtitle="A fresh photo updates the plant's health check"
      close-label="Close photo upload"
      @close="showPhotoUpload = false"
    >
      <input
        type="file"
        accept="image/*"
        capture="environment"
        @change="handlePhotoUpload"
        class="sr-only"
        id="photo-upload"
      >
      <label
        for="photo-upload"
        class="btn-primary w-full mb-3 cursor-pointer"
        :class="{ 'opacity-50': uploadingPhoto }"
      >
        <span v-if="uploadingPhoto" class="flex items-center justify-center gap-2">
          <span class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
          Uploading...
        </span>
        <span v-else>Take Photo</span>
      </label>
      <button type="button" @click="showPhotoUpload = false" class="btn-secondary w-full">
        Cancel
      </button>
    </BaseModal>

    <!-- Delete confirm -->
    <BaseModal
      :visible="showDeleteConfirm"
      variant="center"
      size="sm"
      title="Delete plant?"
      :show-close="false"
      @close="showDeleteConfirm = false"
    >
      <p class="text-charcoal-600 text-sm mb-6">This permanently deletes {{ plant?.name }} and all of its care history.</p>
      <div class="flex gap-3">
        <button type="button" @click="showDeleteConfirm = false" class="btn-secondary flex-1" autofocus>
          Cancel
        </button>
        <button type="button" @click="deletePlant" class="btn flex-1 bg-red-700 text-white hover:bg-red-800">
          Delete
        </button>
      </div>
    </BaseModal>

    <!-- Archive (send to graveyard) -->
    <BaseModal
      :visible="showArchiveModal"
      title="Send to graveyard"
      close-label="Close"
      @close="showArchiveModal = false"
    >
      <p class="text-charcoal-600 text-sm mb-4">
        {{ plant?.name }} will be archived as a memorial. You can still see its photos and care history.
      </p>
      <div class="mb-4">
        <label for="death-reason" class="block text-sm font-medium text-charcoal-600 mb-1">
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
        <button type="button" @click="showArchiveModal = false" class="btn-secondary flex-1">
          Cancel
        </button>
        <button
          type="button"
          @click="archivePlant"
          :disabled="archiving"
          class="btn flex-1 bg-gray-700 text-white hover:bg-gray-800"
        >
          {{ archiving ? 'Archiving...' : 'Archive Plant' }}
        </button>
      </div>
    </BaseModal>

    <!-- Chat -->
    <PlantChatModal
      v-if="plant"
      :plant="plant"
      :visible="showChat"
      @close="showChat = false"
      @plant-updated="refreshPlant"
    />

    <!-- Health status picker -->
    <BaseModal
      :visible="showHealthPicker"
      title="Update health status"
      :subtitle="`How is ${plant?.name} doing?`"
      close-label="Close health status"
      @close="showHealthPicker = false"
    >
      <div class="space-y-2" role="group" aria-label="Health status">
        <button
          v-for="option in healthOptions"
          :key="option.value"
          type="button"
          @click="updateHealthStatus(option.value)"
          :disabled="updatingHealth"
          :aria-pressed="plant?.health_status === option.value"
          class="w-full flex items-center gap-3 p-3 rounded-xl border-2 transition-all text-left"
          :class="plant?.health_status === option.value
            ? 'border-plant-600 bg-plant-50'
            : 'border-gray-200 hover:border-gray-300'"
        >
          <span class="text-2xl" aria-hidden="true">{{ option.emoji }}</span>
          <span class="flex-1">
            <span class="block font-medium text-charcoal-800">{{ option.label }}</span>
            <span class="block text-sm text-charcoal-500">{{ option.desc }}</span>
          </span>
          <svg v-if="plant?.health_status === option.value" class="w-5 h-5 text-plant-700" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
          </svg>
        </button>
      </div>
    </BaseModal>

    <!-- Care log -->
    <CareLogModal
      v-if="plant"
      :plant-id="plant.id"
      :visible="showCareLogModal"
      @close="showCareLogModal = false"
      @logged="handleCareLogged"
    />

    <!-- Shopping list -->
    <BaseModal
      :visible="showShoppingModal"
      title="Add to shopping list"
      :subtitle="`An item for ${plant?.name}`"
      close-label="Close shopping list"
      @close="showShoppingModal = false"
    >
      <div class="space-y-4">
        <div>
          <label for="shopping-item" class="block text-sm font-medium text-charcoal-600 mb-1">Item</label>
          <input
            id="shopping-item"
            v-model="shoppingItem"
            type="text"
            class="input"
            placeholder="e.g., New pot, Fertilizer, Moss pole..."
          >
        </div>
        <div>
          <label for="shopping-notes" class="block text-sm font-medium text-charcoal-600 mb-1">Notes (optional)</label>
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
        <button type="button" @click="showShoppingModal = false" class="btn-secondary flex-1">Cancel</button>
        <button type="button" @click="addShoppingItem" :disabled="savingShoppingItem" class="btn-primary flex-1">
          {{ savingShoppingItem ? 'Adding...' : 'Add to List' }}
        </button>
      </div>
    </BaseModal>

    <!-- Share -->
    <BaseModal
      :visible="showShareModal"
      :title="`Share ${plant?.name || ''}`"
      close-label="Close share"
      @close="showShareModal = false"
    >
      <div class="flex items-center gap-3 p-3 bg-cream-100 rounded-xl mb-4">
        <img
          v-if="photos.length > 0"
          :src="`/uploads/plants/${photos[0].thumbnail || photos[0].filename}`"
          alt=""
          class="w-12 h-12 rounded-lg object-cover"
        >
        <div v-else class="w-12 h-12 rounded-lg bg-gray-200 flex items-center justify-center">
          <img src="https://img.icons8.com/doodle/48/potted-plant--v1.png" alt="" class="w-6 h-6">
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-charcoal-700 truncate">{{ plant?.name }}</p>
          <p v-if="plant?.species" class="text-sm text-charcoal-500 truncate">{{ plant.species }}</p>
        </div>
      </div>

      <div class="mb-4">
        <label for="share-link" class="block text-sm font-medium text-charcoal-600 mb-1">Share link</label>
        <div class="flex gap-2">
          <input
            id="share-link"
            :value="shareUrl"
            readonly
            class="input text-sm flex-1 font-mono text-xs bg-gray-50"
            @click="$event.target.select()"
          >
          <button
            type="button"
            @click="copyShareLink"
            class="btn-primary px-4 flex items-center gap-1.5"
          >
            <svg v-if="linkCopied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <img v-else src="https://img.icons8.com/doodle-line/48/copy.png" alt="" class="w-4 h-4 brightness-0 invert">
            {{ linkCopied ? 'Copied!' : 'Copy' }}
          </button>
        </div>
      </div>

      <div class="space-y-2">
        <p class="text-sm font-medium text-charcoal-600">Share on</p>
        <div class="grid grid-cols-3 gap-2">
          <button
            type="button"
            @click="shareToTwitter"
            class="flex flex-col items-center gap-1 p-3 rounded-xl bg-[#1DA1F2]/10 hover:bg-[#1DA1F2]/20 transition-colors"
          >
            <svg class="w-6 h-6 text-[#1DA1F2]" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
            </svg>
            <span class="text-xs text-charcoal-600">X</span>
          </button>
          <button
            type="button"
            @click="shareToFacebook"
            class="flex flex-col items-center gap-1 p-3 rounded-xl bg-[#1877F2]/10 hover:bg-[#1877F2]/20 transition-colors"
          >
            <svg class="w-6 h-6 text-[#1877F2]" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
            <span class="text-xs text-charcoal-600">Facebook</span>
          </button>
          <button
            type="button"
            @click="shareToWhatsApp"
            class="flex flex-col items-center gap-1 p-3 rounded-xl bg-[#25D366]/10 hover:bg-[#25D366]/20 transition-colors"
          >
            <svg class="w-6 h-6 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            <span class="text-xs text-charcoal-600">WhatsApp</span>
          </button>
        </div>
      </div>

      <button
        v-if="canNativeShare"
        type="button"
        @click="nativeShare"
        class="btn-secondary w-full mt-4 flex items-center justify-center gap-2"
      >
        <img src="https://img.icons8.com/doodle/48/share--v1.png" alt="" class="w-5 h-5">
        More Options
      </button>
    </BaseModal>

    <!-- Loading overlay for slow actions -->
    <LoadingOverlay :visible="isProcessing" :message="loadingMessage" />
  </div>
</template>

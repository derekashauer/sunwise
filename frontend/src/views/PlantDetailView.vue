<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useTasksStore } from '@/stores/tasks'
import { useApi } from '@/composables/useApi'
import TaskItem from '@/components/tasks/TaskItem.vue'
import PlantChatModal from '@/components/chat/PlantChatModal.vue'

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

const healthColors = {
  thriving: 'bg-green-100 text-green-700',
  healthy: 'bg-plant-100 text-plant-700',
  struggling: 'bg-yellow-100 text-yellow-700',
  critical: 'bg-red-100 text-red-700',
  unknown: 'bg-gray-100 text-gray-500'
}

onMounted(async () => {
  try {
    const [plantData, photosData, carePlanData] = await Promise.all([
      plants.getPlant(route.params.id),
      plants.getPhotos(route.params.id),
      api.get(`/plants/${route.params.id}/care-plan`)
    ])
    plant.value = plantData
    photos.value = photosData
    carePlan.value = carePlanData.care_plan
    tasks.value = carePlanData.tasks || []
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
            v-if="plant.species && plant.wikipedia_url"
            :href="plant.wikipedia_url"
            target="_blank"
            rel="noopener"
            class="text-plant-600 text-sm hover:underline inline-flex items-center gap-1"
          >
            {{ plant.species }}
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
          </a>
          <p v-else-if="plant.species" class="text-gray-500 text-sm">{{ plant.species }}</p>
        </div>
        <!-- Chat button -->
        <button @click="showChat = true" class="btn-ghost p-2">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
        </button>
        <!-- Edit button -->
        <button @click="router.push(`/plants/${plant.id}/edit`)" class="btn-ghost p-2">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
          </svg>
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

        <!-- Health badge -->
        <span
          v-if="plant.health_status"
          class="absolute top-3 right-3 px-3 py-1 text-sm font-medium rounded-full capitalize"
          :class="healthColors[plant.health_status]"
        >
          {{ plant.health_status }}
        </span>

        <!-- Add photo button -->
        <button
          @click="showPhotoUpload = true"
          class="absolute bottom-3 right-3 btn-primary px-3 py-2 shadow-lg"
        >
          <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          New Photo
        </button>
      </div>

      <!-- Plant details -->
      <div class="card p-4 mb-6">
        <h2 class="font-semibold text-gray-900 mb-3">Details</h2>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span class="text-gray-500">Location</span>
            <p class="font-medium">{{ plant.location || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Pot Size</span>
            <p class="font-medium capitalize">{{ plant.pot_size || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Soil</span>
            <p class="font-medium capitalize">{{ plant.soil_type || 'Not set' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Light</span>
            <p class="font-medium capitalize">{{ plant.light_condition || 'Not set' }}</p>
          </div>
        </div>
        <p v-if="plant.notes" class="mt-4 text-sm text-gray-600 border-t pt-4">
          {{ plant.notes }}
        </p>
      </div>

      <!-- Care Plan Schedule -->
      <div class="mb-6">
        <h2 class="font-semibold text-gray-900 mb-3">Care Schedule</h2>
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

      <!-- Delete button -->
      <div class="mt-8 pt-6 border-t">
        <button
          @click="showDeleteConfirm = true"
          class="w-full text-red-600 text-sm font-medium py-3"
        >
          Delete Plant
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

    <!-- Chat modal -->
    <PlantChatModal
      v-if="plant"
      :plant="plant"
      :visible="showChat"
      @close="showChat = false"
      @plant-updated="refreshPlant"
    />
  </div>
</template>

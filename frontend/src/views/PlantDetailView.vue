<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import { useTasksStore } from '@/stores/tasks'
import TaskItem from '@/components/tasks/TaskItem.vue'

const router = useRouter()
const route = useRoute()
const plants = usePlantsStore()
const tasksStore = useTasksStore()

const plant = ref(null)
const photos = ref([])
const tasks = ref([])
const loading = ref(true)
const showPhotoUpload = ref(false)
const uploadingPhoto = ref(false)
const showDeleteConfirm = ref(false)

const healthColors = {
  thriving: 'bg-green-100 text-green-700',
  healthy: 'bg-plant-100 text-plant-700',
  struggling: 'bg-yellow-100 text-yellow-700',
  critical: 'bg-red-100 text-red-700',
  unknown: 'bg-gray-100 text-gray-500'
}

onMounted(async () => {
  try {
    const [plantData, photosData, tasksData] = await Promise.all([
      plants.getPlant(route.params.id),
      plants.getPhotos(route.params.id),
      tasksStore.getPlantTasks(route.params.id)
    ])
    plant.value = plantData
    photos.value = photosData
    tasks.value = tasksData
  } catch (e) {
    window.$toast?.error('Failed to load plant')
    router.back()
  } finally {
    loading.value = false
  }
})

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

const upcomingTasks = computed(() => tasks.value.filter(t => !t.completed_at).slice(0, 5))
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
          <p v-if="plant.species" class="text-gray-500 text-sm">{{ plant.species }}</p>
        </div>
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

      <!-- Upcoming tasks -->
      <div v-if="upcomingTasks.length > 0" class="mb-6">
        <h2 class="font-semibold text-gray-900 mb-3">Upcoming Care</h2>
        <div class="space-y-3">
          <TaskItem
            v-for="task in upcomingTasks"
            :key="task.id"
            :task="task"
            :plant="plant"
          />
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
  </div>
</template>

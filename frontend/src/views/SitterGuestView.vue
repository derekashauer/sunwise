<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const loading = ref(true)
const error = ref('')
const session = ref(null)
const tasks = ref([])
const completingTask = ref(null)

const API_BASE = '/api'

onMounted(async () => {
  try {
    const response = await fetch(`${API_BASE}/sitter/${route.params.token}`)
    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.error || 'Session not found')
    }

    session.value = data.session
    tasks.value = data.tasks
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

const pendingTasks = computed(() => tasks.value.filter(t => !t.completed_at))
const completedTasks = computed(() => tasks.value.filter(t => t.completed_at))

const dateRange = computed(() => {
  if (!session.value) return ''
  const start = new Date(session.value.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
  const end = new Date(session.value.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
  return `${start} - ${end}`
})

async function completeTask(taskId) {
  completingTask.value = taskId
  try {
    const response = await fetch(`${API_BASE}/sitter/${route.params.token}/task/${taskId}`, {
      method: 'POST'
    })
    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.error)
    }

    // Update local state
    const index = tasks.value.findIndex(t => t.id === taskId)
    if (index !== -1) {
      tasks.value[index] = data.task
    }
  } catch (e) {
    alert(e.message)
  } finally {
    completingTask.value = null
  }
}

const taskIcons = {
  water: '💧',
  fertilize: '🌱',
  trim: '✂️',
  repot: '🪴',
  rotate: '🔄',
  mist: '💨',
  check: '👀'
}
</script>

<template>
  <div class="min-h-screen bg-plant-50">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center min-h-screen">
      <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex flex-col items-center justify-center min-h-screen px-6">
      <div class="w-16 h-16 mb-4 bg-red-100 rounded-full flex items-center justify-center">
        <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
      </div>
      <h1 class="text-xl font-bold text-gray-900 mb-2">Session Not Found</h1>
      <p class="text-gray-500 text-center">{{ error }}</p>
    </div>

    <!-- Session content -->
    <div v-else class="page-container pb-6">
      <!-- Header -->
      <header class="mb-6">
        <div class="w-12 h-12 mb-4 bg-plant-500 rounded-2xl flex items-center justify-center">
          <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Plant Care Guide</h1>
        <p class="text-gray-500 mt-1">
          <span v-if="session.sitter_name">Hi {{ session.sitter_name }}! </span>
          <span>{{ dateRange }}</span>
        </p>
      </header>

      <!-- Instructions -->
      <div v-if="session.instructions" class="card p-4 mb-6 bg-plant-50 border-plant-200">
        <h2 class="font-semibold text-plant-800 mb-2">Special Instructions</h2>
        <p class="text-sm text-plant-700">{{ session.instructions }}</p>
      </div>

      <!-- Pending tasks -->
      <section v-if="pendingTasks.length > 0" class="mb-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
          Tasks ({{ pendingTasks.length }})
        </h2>
        <div class="space-y-3">
          <div
            v-for="task in pendingTasks"
            :key="task.id"
            class="card p-4"
          >
            <div class="flex items-start gap-4">
              <button
                @click="completeTask(task.id)"
                :disabled="completingTask === task.id"
                class="w-6 h-6 rounded-full border-2 flex-shrink-0 flex items-center justify-center transition-all border-gray-300 hover:border-plant-500"
              >
                <div v-if="completingTask === task.id" class="w-4 h-4 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
              </button>

              <div class="flex-1">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-lg">{{ taskIcons[task.task_type] || '📋' }}</span>
                  <span class="font-medium text-gray-900 capitalize">{{ task.task_type }}</span>
                  <span class="text-sm text-gray-500">· {{ new Date(task.due_date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) }}</span>
                </div>

                <p class="text-sm text-plant-600 font-medium">{{ task.plant_name }}</p>
                <p v-if="task.plant_location" class="text-xs text-gray-500">{{ task.plant_location }}</p>

                <p v-if="task.instructions" class="text-sm text-gray-600 mt-2 bg-gray-50 rounded-lg p-3">
                  {{ task.instructions }}
                </p>
              </div>

              <div v-if="task.plant_thumbnail" class="w-14 h-14 rounded-lg overflow-hidden flex-shrink-0">
                <img :src="`/uploads/plants/${task.plant_thumbnail}`" class="w-full h-full object-cover">
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Completed tasks -->
      <section v-if="completedTasks.length > 0">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
          Completed ({{ completedTasks.length }})
        </h2>
        <div class="space-y-3 opacity-60">
          <div
            v-for="task in completedTasks"
            :key="task.id"
            class="card p-4"
          >
            <div class="flex items-center gap-4">
              <div class="w-6 h-6 rounded-full bg-plant-500 flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <div class="flex-1">
                <span class="text-lg mr-2">{{ taskIcons[task.task_type] || '📋' }}</span>
                <span class="font-medium text-gray-900 capitalize">{{ task.task_type }}</span>
                <span class="text-gray-500"> · {{ task.plant_name }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Plants reference -->
      <section v-if="session.plants && session.plants.length > 0" class="mt-8">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
          Plants
        </h2>
        <div class="grid grid-cols-2 gap-3">
          <div v-for="plant in session.plants" :key="plant.id" class="card overflow-hidden">
            <div class="aspect-square bg-gray-100">
              <img v-if="plant.thumbnail" :src="`/uploads/plants/${plant.thumbnail}`" class="w-full h-full object-cover">
            </div>
            <div class="p-3">
              <h3 class="font-medium text-gray-900 truncate">{{ plant.name }}</h3>
              <p v-if="plant.location" class="text-xs text-gray-500">{{ plant.location }}</p>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

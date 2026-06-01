<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const loading = ref(true)
const error = ref('')
const session = ref(null)
const tasks = ref([])
const completingTask = ref(null)
const lightboxSrc = ref(null)
const lightboxAlt = ref('')

const API_BASE = '/api'

onMounted(async () => {
  try {
    const response = await fetch(`${API_BASE}/sitter/${route.params.token}`)
    const data = await response.json()
    if (!response.ok) throw new Error(data.error || 'Session not found')
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

// Parse 'YYYY-MM-DD' as a LOCAL date — new Date('2026-06-03') parses as UTC
// midnight, which then renders as the previous day in any timezone west of UTC.
function parseLocalDate(s) {
  if (!s) return null
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y, m - 1, d)
}

function formatDate(s, opts = { month: 'short', day: 'numeric' }) {
  const d = parseLocalDate(s)
  return d ? d.toLocaleDateString('en-US', opts) : ''
}

const dateRange = computed(() => {
  if (!session.value) return ''
  return `${formatDate(session.value.start_date)} - ${formatDate(session.value.end_date)}`
})

const today = new Date()
today.setHours(0, 0, 0, 0)

function isOverdue(dueDate) {
  const d = parseLocalDate(dueDate)
  return d && d < today
}

// Task type priority — same order as DashboardView so the sitter sees them
// in the same order the owner would.
const taskTypePriority = {
  water: 1, change_water: 2, mist: 3, fertilize: 4, check: 5,
  check_roots: 6, rotate: 7, trim: 8, repot: 9, pot_up: 10
}

function getTaskPriority(t) {
  return taskTypePriority[t] ?? 99
}

// Doodle icons matching TaskItem.vue
const taskIcons = {
  water: 'https://img.icons8.com/doodle/48/watering-can.png',
  fertilize: 'https://img.icons8.com/doodle/48/nature-care.png',
  trim: 'https://img.icons8.com/doodle/48/cut.png',
  repot: 'https://img.icons8.com/doodle/48/potted-plant.png',
  rotate: 'https://img.icons8.com/doodle/48/rotate.png',
  mist: 'https://img.icons8.com/doodle/48/splash.png',
  check: 'https://img.icons8.com/doodle/48/visible--v1.png',
  change_water: 'https://img.icons8.com/doodle/48/water.png',
  check_roots: 'https://img.icons8.com/doodle/48/soil.png',
  pot_up: 'https://img.icons8.com/doodle/48/potted-plant.png'
}

function getTaskIcon(taskType) {
  return taskIcons[taskType] || 'https://img.icons8.com/doodle/48/todo-list.png'
}

// Group items by location, "Unassigned" last; tasks within a location sort by type priority
function groupTasksByLocation(items) {
  const groups = new Map()
  for (const t of items) {
    const loc = t.plant_location || 'Unassigned'
    if (!groups.has(loc)) groups.set(loc, [])
    groups.get(loc).push(t)
  }
  return Array.from(groups.entries())
    .map(([location, tasks]) => ({
      location,
      tasks: tasks.sort((a, b) => getTaskPriority(a.task_type) - getTaskPriority(b.task_type))
    }))
    .sort((a, b) => {
      if (a.location === 'Unassigned') return 1
      if (b.location === 'Unassigned') return -1
      return a.location.localeCompare(b.location)
    })
}

function groupPlantsByLocation(items) {
  const groups = new Map()
  for (const p of items) {
    const loc = p.location || 'Unassigned'
    if (!groups.has(loc)) groups.set(loc, [])
    groups.get(loc).push(p)
  }
  return Array.from(groups.entries())
    .map(([location, plants]) => ({
      location,
      plants: plants.sort((a, b) => a.name.localeCompare(b.name))
    }))
    .sort((a, b) => {
      if (a.location === 'Unassigned') return 1
      if (b.location === 'Unassigned') return -1
      return a.location.localeCompare(b.location)
    })
}

const pendingTasksByLocation = computed(() => groupTasksByLocation(pendingTasks.value))
const plantsByLocation = computed(() => groupPlantsByLocation(session.value?.plants || []))

async function completeTask(taskId) {
  completingTask.value = taskId
  try {
    const response = await fetch(`${API_BASE}/sitter/${route.params.token}/task/${taskId}`, {
      method: 'POST'
    })
    const data = await response.json()
    if (!response.ok) throw new Error(data.error)
    const idx = tasks.value.findIndex(t => t.id === taskId)
    if (idx !== -1) tasks.value[idx] = data.task
  } catch (e) {
    alert(e.message)
  } finally {
    completingTask.value = null
  }
}

function openLightbox(src, alt) {
  lightboxSrc.value = src
  lightboxAlt.value = alt
}
</script>

<template>
  <div class="min-h-screen bg-cream-50">
    <!-- Loading -->
    <div v-if="loading" class="flex flex-col items-center justify-center min-h-screen">
      <img
        src="https://img.icons8.com/doodle/96/watering-can.png"
        alt="loading"
        class="w-16 h-16 animate-bounce"
      >
      <p class="text-charcoal-400 mt-4 font-hand text-xl">Loading your plant care guide...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex flex-col items-center justify-center min-h-screen px-6">
      <div class="w-16 h-16 mb-4 bg-terracotta-100 rounded-2xl flex items-center justify-center">
        <svg class="w-8 h-8 text-terracotta-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
      </div>
      <h1 class="font-hand text-2xl text-charcoal-700 mb-2">Session Not Found</h1>
      <p class="text-charcoal-400 text-center">{{ error }}</p>
    </div>

    <!-- Session content -->
    <div v-else class="page-container pb-6">
      <!-- Header -->
      <header class="mb-6">
        <div class="w-12 h-12 mb-4 bg-sage-100 rounded-2xl flex items-center justify-center">
          <img src="https://img.icons8.com/doodle/48/potted-plant--v1.png" alt="" class="w-8 h-8">
        </div>
        <h1 class="font-hand text-3xl text-charcoal-700">
          <span v-if="session.sitter_name">Hi {{ session.sitter_name }}!</span>
          <span v-else>Plant Care Guide</span>
        </h1>
        <p class="text-charcoal-400 mt-1">{{ dateRange }}</p>
      </header>

      <!-- Special instructions -->
      <div v-if="session.instructions" class="card p-4 mb-6 bg-sage-50 border border-sage-200">
        <h2 class="font-semibold text-sage-800 mb-2 flex items-center gap-2">
          <img src="https://img.icons8.com/doodle/48/note.png" alt="" class="w-5 h-5">
          Special Instructions
        </h2>
        <p class="text-sm text-charcoal-600 whitespace-pre-line">{{ session.instructions }}</p>
      </div>

      <!-- Empty state -->
      <div v-if="pendingTasks.length === 0 && completedTasks.length === 0" class="text-center py-12">
        <div class="w-24 h-24 mx-auto mb-4 bg-sage-100 rounded-3xl flex items-center justify-center shadow-sage">
          <img src="https://img.icons8.com/doodle/96/ok--v1.png" alt="all done" class="w-14 h-14">
        </div>
        <h2 class="font-hand text-2xl text-charcoal-600 mb-2">No tasks scheduled</h2>
        <p class="text-charcoal-400">Nothing for you to do during this window.</p>
      </div>

      <!-- Pending tasks grouped by location -->
      <section v-if="pendingTasks.length > 0" class="space-y-6 mb-8">
        <div v-for="group in pendingTasksByLocation" :key="group.location" class="space-y-2">
          <div class="flex items-center gap-2 text-sm text-charcoal-500">
            <img src="https://img.icons8.com/doodle/48/place-marker.png" alt="" class="w-5 h-5">
            <span class="font-semibold capitalize">{{ group.location }}</span>
            <span class="text-charcoal-300 bg-cream-200 px-2 py-0.5 rounded-full text-xs">{{ group.tasks.length }}</span>
          </div>
          <div class="space-y-2">
            <div
              v-for="task in group.tasks"
              :key="task.id"
              class="card overflow-hidden"
            >
              <div class="flex items-center gap-3 p-3">
                <!-- Complete button -->
                <button
                  @click="completeTask(task.id)"
                  :disabled="completingTask === task.id"
                  class="w-10 h-10 rounded-full border-2 border-sage-300 hover:border-sage-500 hover:bg-sage-50 flex-shrink-0 flex items-center justify-center transition-all disabled:opacity-50"
                  :title="`Complete ${task.task_type}`"
                >
                  <div v-if="completingTask === task.id" class="w-5 h-5 border-2 border-sage-500 border-t-transparent rounded-full animate-spin"></div>
                  <svg v-else class="w-5 h-5 text-sage-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                  </svg>
                </button>

                <!-- Plant thumbnail (clickable to enlarge) -->
                <button
                  v-if="task.plant_thumbnail"
                  @click="openLightbox(`/uploads/plants/${task.plant_thumbnail}`, task.plant_name)"
                  class="w-20 h-20 rounded-xl overflow-hidden flex-shrink-0 border-2 border-sage-200 hover:border-sage-400 transition-colors cursor-pointer"
                >
                  <img :src="`/uploads/plants/${task.plant_thumbnail}`" :alt="task.plant_name" class="w-full h-full object-cover">
                </button>
                <div v-else class="w-20 h-20 rounded-xl bg-cream-200 flex items-center justify-center flex-shrink-0">
                  <img :src="getTaskIcon(task.task_type)" :alt="task.task_type" class="w-10 h-10">
                </div>

                <!-- Task info -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <img :src="getTaskIcon(task.task_type)" :alt="task.task_type" class="w-5 h-5">
                    <span class="font-semibold text-charcoal-600 capitalize text-sm">{{ task.task_type.replace('_', ' ') }}</span>
                    <span
                      v-if="task.priority === 'urgent' || task.priority === 'high'"
                      class="px-1.5 py-0.5 text-xs font-medium rounded-full"
                      :class="task.priority === 'urgent' ? 'bg-terracotta-100 text-terracotta-700' : 'bg-sunny-100 text-sunny-700'"
                    >
                      {{ task.priority }}
                    </span>
                    <span
                      v-if="isOverdue(task.due_date)"
                      class="px-1.5 py-0.5 text-xs font-medium rounded-full bg-terracotta-100 text-terracotta-700"
                    >
                      Overdue
                    </span>
                  </div>
                  <p class="text-sm text-charcoal-400 truncate">{{ task.plant_name }}</p>
                  <p class="text-xs text-charcoal-300 mt-0.5">
                    Due {{ formatDate(task.due_date, { weekday: 'short', month: 'short', day: 'numeric' }) }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Completed tasks -->
      <section v-if="completedTasks.length > 0" class="space-y-2 mb-8">
        <div class="flex items-center gap-2 text-sm text-charcoal-400">
          <img src="https://img.icons8.com/doodle/48/checkmark--v1.png" alt="" class="w-5 h-5 opacity-60">
          <span class="font-semibold">Completed</span>
          <span class="text-charcoal-300 bg-cream-200 px-2 py-0.5 rounded-full text-xs">{{ completedTasks.length }}</span>
        </div>
        <div class="space-y-2 opacity-60">
          <div
            v-for="task in completedTasks"
            :key="task.id"
            class="card p-3 flex items-center gap-3"
          >
            <div class="w-10 h-10 rounded-full bg-sage-500 flex-shrink-0 flex items-center justify-center">
              <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <img :src="getTaskIcon(task.task_type)" :alt="task.task_type" class="w-5 h-5">
            <span class="font-semibold text-charcoal-600 capitalize text-sm">{{ task.task_type.replace('_', ' ') }}</span>
            <span class="text-sm text-charcoal-400">· {{ task.plant_name }}</span>
          </div>
        </div>
      </section>

      <!-- Plants reference grouped by location -->
      <section v-if="session.plants && session.plants.length > 0" class="mt-8">
        <h2 class="font-hand text-2xl text-charcoal-700 mb-3">Your Plants</h2>
        <div v-for="group in plantsByLocation" :key="group.location" class="mb-5">
          <div class="flex items-center gap-2 text-sm text-charcoal-500 mb-2">
            <img src="https://img.icons8.com/doodle/48/place-marker.png" alt="" class="w-5 h-5">
            <span class="font-semibold capitalize">{{ group.location }}</span>
            <span class="text-charcoal-300 bg-cream-200 px-2 py-0.5 rounded-full text-xs">{{ group.plants.length }}</span>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div v-for="plant in group.plants" :key="plant.id" class="card overflow-hidden">
              <button
                v-if="plant.thumbnail"
                @click="openLightbox(`/uploads/plants/${plant.thumbnail}`, plant.name)"
                class="block w-full aspect-square bg-cream-100 cursor-pointer"
              >
                <img :src="`/uploads/plants/${plant.thumbnail}`" :alt="plant.name" class="w-full h-full object-cover">
              </button>
              <div v-else class="aspect-square bg-cream-100 flex items-center justify-center">
                <img src="https://img.icons8.com/doodle/96/potted-plant--v1.png" alt="" class="w-12 h-12 opacity-60">
              </div>
              <div class="p-3">
                <h3 class="font-medium text-charcoal-600 truncate">{{ plant.name }}</h3>
                <p v-if="plant.species" class="text-xs text-charcoal-400 italic truncate">{{ plant.species }}</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Image lightbox -->
    <Teleport to="body">
      <div
        v-if="lightboxSrc"
        class="fixed inset-0 bg-black/80 flex items-center justify-center z-50 p-4"
        @click.self="lightboxSrc = null"
      >
        <div class="relative max-w-lg w-full">
          <button
            @click="lightboxSrc = null"
            class="absolute -top-12 right-0 text-white/80 hover:text-white p-2"
          >
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <img :src="lightboxSrc" :alt="lightboxAlt" class="w-full rounded-2xl shadow-2xl">
          <p class="text-white text-center mt-3 font-medium">{{ lightboxAlt }}</p>
        </div>
      </div>
    </Teleport>
  </div>
</template>

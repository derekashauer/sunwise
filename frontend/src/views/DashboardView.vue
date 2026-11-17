<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'
import { usePlantsStore } from '@/stores/plants'
import TaskItem from '@/components/tasks/TaskItem.vue'
import AiStatusBanner from '@/components/common/AiStatusBanner.vue'
import InstallHint from '@/components/common/InstallHint.vue'
import { getTaskIcon, getTaskLabel, getTaskOrder } from '@/constants/taskTypes'
import { todayLocal } from '@/utils/dates'

const router = useRouter()
const tasks = useTasksStore()
const plants = usePlantsStore()

const completingGroup = ref(null)
const skippingGroup = ref(null)
const confirmSkipGroup = ref(null)
const groupBy = ref('location') // 'location' or 'task'
const loadedOnce = ref(false)
let lastLoadedDay = null

async function load() {
  lastLoadedDay = todayLocal()
  await Promise.all([
    tasks.fetchTodayTasks(),
    plants.fetchPlants()
  ])
  loadedOnce.value = true
}

// Reload when the app comes back to the front, so a phone left open overnight
// shows today's list instead of yesterday's. Short trips away only reload if
// a few minutes have passed.
let lastLoadedAt = 0
async function onVisibilityChange() {
  if (document.visibilityState !== 'visible') return
  const newDay = todayLocal() !== lastLoadedDay
  if (newDay || Date.now() - lastLoadedAt > 5 * 60 * 1000) {
    lastLoadedAt = Date.now()
    await tasks.syncOutbox()
    await load()
  }
}

onMounted(async () => {
  lastLoadedAt = Date.now()
  await load()
  document.addEventListener('visibilitychange', onVisibilityChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange)
})

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning!'
  if (hour < 18) return 'Good afternoon!'
  return 'Good evening!'
})

const pendingTasks = computed(() => tasks.todayTasks.filter(t => !t.completed_at))
const completedTasks = computed(() => tasks.todayTasks.filter(t => t.completed_at))
const lateCount = computed(() => pendingTasks.value.filter(t => Number(t.days_overdue) > 0).length)
const hasPlants = computed(() => plants.plants.length > 0)
const showLoading = computed(() => tasks.loading && !loadedOnce.value)
const showError = computed(() => !!tasks.error && tasks.todayTasks.length === 0)

const NO_LOCATION = 'No Location'

function getPlant(plantId) {
  return plants.getPlantById(plantId)
}

function getLocationName(task) {
  return getPlant(task.plant_id)?.location_name || task.plant_location || NO_LOCATION
}

// Alphabetical by location, with "No Location" always last
function compareLocationNames(a, b) {
  if (a === NO_LOCATION) return 1
  if (b === NO_LOCATION) return -1
  return a.localeCompare(b)
}

// Late tasks first, the latest at the top.
function compareLateness(a, b) {
  return (Number(b.days_overdue) || 0) - (Number(a.days_overdue) || 0)
}

// Order tasks by the room they're in, so a list can be worked room by room.
// Ties break on plant name to keep the order stable between renders.
function compareByLocation(a, b) {
  const locA = getLocationName(a)
  const locB = getLocationName(b)
  if (locA !== locB) return compareLocationNames(locA, locB)
  return (a.plant_name || '').localeCompare(b.plant_name || '')
}

const tasksByLocation = computed(() => {
  const groups = {}
  for (const task of pendingTasks.value) {
    const locationName = getLocationName(task)
    if (!groups[locationName]) groups[locationName] = []
    groups[locationName].push(task)
  }
  return Object.keys(groups).sort(compareLocationNames).map(name => ({
    key: `loc:${name}`,
    name,
    tasks: groups[name].sort((a, b) =>
      compareLateness(a, b) || getTaskOrder(a.task_type) - getTaskOrder(b.task_type)
    )
  }))
})

// Within each task type, late tasks first, then by location so the
// room-by-room walk still works when grouping by task.
const tasksByType = computed(() => {
  const groups = {}
  for (const task of pendingTasks.value) {
    if (!groups[task.task_type]) groups[task.task_type] = []
    groups[task.task_type].push(task)
  }
  return Object.keys(groups)
    .sort((a, b) => getTaskOrder(a) - getTaskOrder(b))
    .map(type => ({
      key: `type:${type}`,
      name: getTaskLabel(type),
      taskType: type,
      tasks: groups[type].sort((a, b) => compareLateness(a, b) || compareByLocation(a, b))
    }))
})

const taskGroups = computed(() => groupBy.value === 'location' ? tasksByLocation.value : tasksByType.value)

// Check tasks need their readings, so they are never completed in bulk.
function bulkCompletable(group) {
  return group.tasks.filter(t => t.task_type !== 'check')
}

async function completeAllInGroup(group) {
  if (completingGroup.value) return
  const taskIds = bulkCompletable(group).map(t => t.id)
  if (taskIds.length === 0) return
  completingGroup.value = group.key
  confirmSkipGroup.value = null

  try {
    const response = await tasks.bulkCompleteTasks(taskIds)
    const doneIds = response.completed || []
    let message = `Completed ${doneIds.length} task${doneIds.length === 1 ? '' : 's'} in ${group.name}`
    if ((response.skipped_check || []).length > 0 || group.tasks.length > taskIds.length) {
      message += '. Check tasks need the check form.'
    }
    window.$toast?.success(message, {
      duration: 6000,
      action: doneIds.length
        ? {
            label: 'Undo',
            onClick: async () => {
              await tasks.bulkUncompleteTasks(doneIds)
              window.$toast?.info('Those tasks are back on your list')
            }
          }
        : null
    })
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't complete those tasks.")
  } finally {
    completingGroup.value = null
  }
}

function askSkipAll(group) {
  confirmSkipGroup.value = confirmSkipGroup.value === group.key ? null : group.key
}

async function skipAllInGroup(group) {
  if (skippingGroup.value) return
  skippingGroup.value = group.key
  confirmSkipGroup.value = null

  try {
    const taskIds = group.tasks.map(t => t.id)
    const response = await tasks.bulkSkipTasks(taskIds, 'Bulk skipped')
    const count = (response.skipped || []).length
    window.$toast?.success(`Skipped ${count} task${count === 1 ? '' : 's'} in ${group.name}`)
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't skip those tasks.")
  } finally {
    skippingGroup.value = null
  }
}

async function retry() {
  await load()
}
</script>

<template>
  <div class="page-container">
    <AiStatusBanner />

    <header class="mb-6">
      <h1 class="page-title">{{ greeting }}</h1>
      <div class="text-charcoal-500 mt-1">
        <p v-if="tasks.todayCount > 0" class="flex items-center gap-2">
          <img src="https://img.icons8.com/doodle/48/reminders.png" alt="" class="w-5 h-5">
          <span>
            You have <span class="font-bold text-terracotta-700">{{ tasks.todayCount }}</span>
            task{{ tasks.todayCount !== 1 ? 's' : '' }} today<template v-if="lateCount > 0">, {{ lateCount }} late</template>
          </span>
        </p>
        <p v-else-if="!showLoading && !showError && hasPlants" class="flex items-center gap-2">
          <img src="https://img.icons8.com/doodle/48/potted-plant--v1.png" alt="" class="w-5 h-5">
          All caught up! Your plants are happy.
        </p>
        <p v-if="tasks.pendingSync > 0" class="mt-2 text-sm text-sunny-900 bg-sunny-100 inline-block px-3 py-1 rounded-full">
          {{ tasks.pendingSync }} change{{ tasks.pendingSync === 1 ? '' : 's' }} waiting to sync
        </p>
      </div>
    </header>

    <InstallHint />

    <!-- Loading state (first load only) -->
    <div v-if="showLoading" class="flex flex-col items-center justify-center py-12" role="status">
      <img src="https://img.icons8.com/doodle/96/watering-can.png" alt="" class="w-16 h-16 motion-safe:animate-bounce">
      <p class="text-charcoal-500 mt-4 font-hand text-xl">Getting your tasks...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="showError" class="card p-6 text-center" role="alert">
      <h2 class="font-hand text-2xl text-charcoal-700 mb-2">Couldn't load today's tasks</h2>
      <p class="text-charcoal-600 mb-4">{{ tasks.error }}</p>
      <button type="button" @click="retry" class="btn-primary">Try again</button>
    </div>

    <!-- Empty state: nothing due -->
    <div v-else-if="pendingTasks.length === 0 && hasPlants" class="text-center py-12">
      <div class="w-24 h-24 mx-auto mb-4 bg-sage-100 rounded-3xl flex items-center justify-center shadow-sage">
        <img src="https://img.icons8.com/doodle/96/ok--v1.png" alt="" class="w-14 h-14">
      </div>
      <h2 class="font-hand text-2xl text-charcoal-600 mb-2">Nothing due today</h2>
      <p class="text-charcoal-500">Your plants are taken care of. Check back tomorrow.</p>
    </div>

    <!-- Empty state: no plants yet -->
    <div v-else-if="pendingTasks.length === 0" class="text-center py-12">
      <div class="w-24 h-24 mx-auto mb-4 bg-sage-100 rounded-3xl flex items-center justify-center shadow-sage">
        <img src="https://img.icons8.com/doodle/96/potted-plant--v1.png" alt="" class="w-14 h-14">
      </div>
      <h2 class="font-hand text-2xl text-charcoal-600 mb-2">No plants yet</h2>
      <p class="text-charcoal-500 mb-6">Add a plant to get a care schedule made for it.</p>
      <button type="button" @click="router.push('/plants/add')" class="btn-primary">
        <img src="https://img.icons8.com/doodle/48/potted-plant--v1.png" alt="" class="w-5 h-5 mr-2">
        Add your first plant
      </button>
    </div>

    <!-- Task list grouped -->
    <div v-else class="space-y-6">
      <div class="flex items-center justify-end gap-2">
        <span id="group-by-label" class="text-xs text-charcoal-500">Group by:</span>
        <div class="inline-flex rounded-lg bg-cream-200 p-0.5" role="group" aria-labelledby="group-by-label">
          <button
            type="button"
            @click="groupBy = 'location'"
            class="px-3 py-2 text-xs font-medium rounded-md transition-all"
            :class="groupBy === 'location' ? 'bg-white text-charcoal-700 shadow-sm' : 'text-charcoal-500'"
            :aria-pressed="groupBy === 'location'"
          >
            <img src="https://img.icons8.com/doodle/48/place-marker.png" alt="" class="w-4 h-4 inline mr-1">
            Location
          </button>
          <button
            type="button"
            @click="groupBy = 'task'"
            class="px-3 py-2 text-xs font-medium rounded-md transition-all"
            :class="groupBy === 'task' ? 'bg-white text-charcoal-700 shadow-sm' : 'text-charcoal-500'"
            :aria-pressed="groupBy === 'task'"
          >
            <img src="https://img.icons8.com/doodle/48/todo-list.png" alt="" class="w-4 h-4 inline mr-1">
            Task
          </button>
        </div>
      </div>

      <section class="space-y-4" aria-label="Today's tasks">
        <div v-for="group in taskGroups" :key="group.key" class="space-y-2">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <h2 class="flex items-center gap-2 text-sm text-charcoal-600 font-sans">
              <img
                :src="groupBy === 'location' ? 'https://img.icons8.com/doodle/48/place-marker.png' : getTaskIcon(group.taskType)"
                alt=""
                class="w-5 h-5"
              >
              <span class="font-semibold">{{ group.name }}</span>
              <span class="text-charcoal-600 bg-cream-200 px-2 py-0.5 rounded-full text-xs">{{ group.tasks.length }}</span>
            </h2>

            <!-- Bulk actions -->
            <div v-if="group.tasks.length > 1" class="flex items-center gap-2">
              <button
                v-if="bulkCompletable(group).length > 0"
                type="button"
                @click="completeAllInGroup(group)"
                :disabled="completingGroup === group.key || skippingGroup === group.key"
                class="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-sage-800 bg-sage-100 hover:bg-sage-200 rounded-full transition-colors disabled:opacity-50"
              >
                <img src="https://img.icons8.com/doodle/48/checkmark--v1.png" alt="" class="w-4 h-4">
                <span>{{ completingGroup === group.key ? 'Working...' : 'Complete all' }}</span>
              </button>
              <button
                type="button"
                @click="askSkipAll(group)"
                :disabled="skippingGroup === group.key || completingGroup === group.key"
                class="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-charcoal-600 bg-charcoal-100 hover:bg-charcoal-200 rounded-full transition-colors disabled:opacity-50"
                :aria-expanded="confirmSkipGroup === group.key"
              >
                <img src="https://img.icons8.com/doodle/48/forward.png" alt="" class="w-4 h-4">
                <span>{{ skippingGroup === group.key ? 'Skipping...' : 'Skip all' }}</span>
              </button>
            </div>
          </div>

          <!-- Skip all confirmation -->
          <div
            v-if="confirmSkipGroup === group.key"
            class="flex items-center justify-between gap-3 p-3 rounded-xl bg-cream-200 text-sm"
            role="alert"
          >
            <span class="text-charcoal-700">Skip all {{ group.tasks.length }} tasks in {{ group.name }}? This can't be undone.</span>
            <div class="flex gap-2 flex-shrink-0">
              <button type="button" @click="confirmSkipGroup = null" class="px-3 py-2 rounded-lg font-semibold text-charcoal-600 hover:bg-cream-300">Cancel</button>
              <button type="button" @click="skipAllInGroup(group)" class="px-3 py-2 rounded-lg font-semibold bg-charcoal-700 text-white hover:bg-charcoal-800">Skip all</button>
            </div>
          </div>

          <div class="space-y-2">
            <TaskItem
              v-for="task in group.tasks"
              :key="task.id"
              :task="task"
              :plant="getPlant(task.plant_id)"
              :show-location="groupBy === 'task'"
            />
          </div>
        </div>
      </section>
    </div>

    <!-- Completed tasks link -->
    <section v-if="completedTasks.length > 0 && !showLoading" class="mt-6">
      <router-link
        to="/tasks/completed"
        class="flex items-center justify-center gap-2 py-3 text-sm text-charcoal-500 hover:text-charcoal-700 transition-colors"
      >
        <img src="https://img.icons8.com/doodle/48/checkmark--v1.png" alt="" class="w-5 h-5 opacity-60">
        <span>{{ completedTasks.length }} task{{ completedTasks.length !== 1 ? 's' : '' }} done today</span>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </router-link>
    </section>
  </div>
</template>

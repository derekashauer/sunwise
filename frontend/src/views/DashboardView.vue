<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'
import { usePlantsStore } from '@/stores/plants'
import TaskItem from '@/components/tasks/TaskItem.vue'
import AiStatusBanner from '@/components/common/AiStatusBanner.vue'

const router = useRouter()
const tasks = useTasksStore()
const plants = usePlantsStore()

const completingLocation = ref(null)
const completingTaskType = ref(null)
const groupBy = ref('location') // 'location' or 'task'

onMounted(async () => {
  await Promise.all([
    tasks.fetchTodayTasks(),
    plants.fetchPlants()
  ])
})

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning!'
  if (hour < 18) return 'Good afternoon!'
  return 'Good evening!'
})

const pendingTasks = computed(() => tasks.todayTasks.filter(t => !t.completed_at))
const completedTasks = computed(() => tasks.todayTasks.filter(t => t.completed_at))

// Task type sort order (most common/important first)
const taskTypePriority = {
  water: 1,
  change_water: 2,
  mist: 3,
  fertilize: 4,
  check: 5,
  check_roots: 6,
  rotate: 7,
  trim: 8,
  repot: 9,
  pot_up: 10
}

function getTaskPriority(taskType) {
  return taskTypePriority[taskType] ?? 99
}

// Group pending tasks by location
const tasksByLocation = computed(() => {
  const groups = {}
  for (const task of pendingTasks.value) {
    const plant = getPlant(task.plant_id)
    const locationName = plant?.location_name || 'No Location'
    if (!groups[locationName]) {
      groups[locationName] = []
    }
    groups[locationName].push(task)
  }
  // Sort location names, putting "No Location" last
  const sortedLocations = Object.keys(groups).sort((a, b) => {
    if (a === 'No Location') return 1
    if (b === 'No Location') return -1
    return a.localeCompare(b)
  })
  // Sort tasks within each location by task type
  return sortedLocations.map(name => ({
    name,
    tasks: groups[name].sort((a, b) => getTaskPriority(a.task_type) - getTaskPriority(b.task_type))
  }))
})

// Group pending tasks by task type
const tasksByType = computed(() => {
  const groups = {}
  for (const task of pendingTasks.value) {
    const taskType = task.task_type.replace('_', ' ')
    if (!groups[taskType]) {
      groups[taskType] = []
    }
    groups[taskType].push(task)
  }
  // Sort task types alphabetically
  const sortedTypes = Object.keys(groups).sort()
  return sortedTypes.map(name => ({ name, tasks: groups[name], taskType: name.replace(' ', '_') }))
})

const taskGroups = computed(() => {
  return groupBy.value === 'location' ? tasksByLocation.value : tasksByType.value
})

function getPlant(plantId) {
  return plants.getPlantById(plantId)
}

function getTaskIcon(taskType) {
  const icons = {
    water: 'https://img.icons8.com/doodle/48/watering-can.png',
    fertilize: 'https://img.icons8.com/doodle/48/fertilization--v1.png',
    trim: 'https://img.icons8.com/doodle/48/cut.png',
    repot: 'https://img.icons8.com/doodle/48/potted-plant.png',
    rotate: 'https://img.icons8.com/doodle/48/rotate-right.png',
    mist: 'https://img.icons8.com/doodle/48/splash.png',
    check: 'https://img.icons8.com/doodle/48/visible--v1.png',
    change_water: 'https://img.icons8.com/doodle/48/water.png',
    check_roots: 'https://img.icons8.com/doodle/48/root.png'
  }
  return icons[taskType] || 'https://img.icons8.com/doodle/48/todo-list.png'
}

async function completeAllInGroup(group) {
  if (groupBy.value === 'location') {
    if (completingLocation.value) return
    completingLocation.value = group.name
  } else {
    if (completingTaskType.value) return
    completingTaskType.value = group.name
  }

  try {
    const taskIds = group.tasks.map(t => t.id)
    await tasks.bulkCompleteTasks(taskIds)
    window.$toast?.success(`Completed ${taskIds.length} ${group.name} tasks`)
  } catch (e) {
    window.$toast?.error('Failed to complete tasks')
  } finally {
    completingLocation.value = null
    completingTaskType.value = null
  }
}

function isGroupCompleting(groupName) {
  return groupBy.value === 'location'
    ? completingLocation.value === groupName
    : completingTaskType.value === groupName
}
</script>

<template>
  <div class="page-container">
    <!-- AI Status Banner -->
    <AiStatusBanner />

    <header class="mb-6">
      <h1 class="page-title">{{ greeting }}</h1>
      <p class="text-charcoal-400 mt-1">
        <span v-if="tasks.todayCount > 0" class="flex items-center gap-2">
          <img
            src="https://img.icons8.com/doodle/48/reminders.png"
            alt="reminders"
            class="w-5 h-5"
          >
          You have <span class="font-bold text-terracotta-500">{{ tasks.todayCount }}</span> task{{ tasks.todayCount !== 1 ? 's' : '' }} today
        </span>
        <span v-else class="flex items-center gap-2">
          <img
            src="https://img.icons8.com/doodle/48/potted-plant--v1.png"
            alt="plant"
            class="w-5 h-5"
          >
          All caught up! Your plants are happy.
        </span>
      </p>
    </header>

    <!-- Loading state -->
    <div v-if="tasks.loading" class="flex flex-col items-center justify-center py-12">
      <img
        src="https://img.icons8.com/doodle/96/watering-can.png"
        alt="loading"
        class="w-16 h-16 animate-bounce"
      >
      <p class="text-charcoal-400 mt-4 font-hand text-xl">Getting your tasks...</p>
    </div>

    <!-- Empty state -->
    <div v-else-if="tasks.todayTasks.length === 0" class="text-center py-12">
      <div class="w-24 h-24 mx-auto mb-4 bg-sage-100 rounded-3xl flex items-center justify-center shadow-sage">
        <img
          src="https://img.icons8.com/doodle/96/ok--v1.png"
          alt="all done"
          class="w-14 h-14"
        >
      </div>
      <h2 class="font-hand text-2xl text-charcoal-600 mb-2">No tasks today!</h2>
      <p class="text-charcoal-400 mb-6">Add some plants to get personalized care tasks</p>
      <button @click="router.push('/plants/add')" class="btn-primary">
        <img
          src="https://img.icons8.com/doodle/48/potted-plant--v1.png"
          alt=""
          class="w-5 h-5 mr-2"
        >
        Add Your First Plant
      </button>
    </div>

    <!-- Task list grouped -->
    <div v-else class="space-y-6">
      <!-- Group by toggle -->
      <div v-if="pendingTasks.length > 0" class="flex items-center justify-end gap-2">
        <span class="text-xs text-charcoal-400">Group by:</span>
        <div class="inline-flex rounded-lg bg-cream-200 p-0.5">
          <button
            @click="groupBy = 'location'"
            class="px-3 py-1 text-xs font-medium rounded-md transition-all"
            :class="groupBy === 'location' ? 'bg-white text-charcoal-700 shadow-sm' : 'text-charcoal-500'"
          >
            <img
              src="https://img.icons8.com/doodle/48/place-marker.png"
              alt=""
              class="w-4 h-4 inline mr-1"
            >
            Location
          </button>
          <button
            @click="groupBy = 'task'"
            class="px-3 py-1 text-xs font-medium rounded-md transition-all"
            :class="groupBy === 'task' ? 'bg-white text-charcoal-700 shadow-sm' : 'text-charcoal-500'"
          >
            <img
              src="https://img.icons8.com/doodle/48/todo-list.png"
              alt=""
              class="w-4 h-4 inline mr-1"
            >
            Task
          </button>
        </div>
      </div>

      <!-- Pending tasks by group -->
      <section v-if="taskGroups.length > 0">
        <div class="space-y-4">
          <div v-for="group in taskGroups" :key="group.name" class="space-y-2">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2 text-sm text-charcoal-500">
                <img
                  v-if="groupBy === 'location'"
                  src="https://img.icons8.com/doodle/48/place-marker.png"
                  alt="location"
                  class="w-5 h-5"
                >
                <img
                  v-else
                  :src="getTaskIcon(group.taskType)"
                  alt="task"
                  class="w-5 h-5"
                >
                <span class="font-semibold capitalize">{{ group.name }}</span>
                <span class="text-charcoal-300 bg-cream-200 px-2 py-0.5 rounded-full text-xs">{{ group.tasks.length }}</span>
              </div>
              <!-- Complete All button -->
              <button
                v-if="group.tasks.length > 1"
                @click="completeAllInGroup(group)"
                :disabled="isGroupCompleting(group.name)"
                class="flex items-center gap-1.5 px-3 py-1 text-xs font-medium text-sage-700 bg-sage-100 hover:bg-sage-200 rounded-full transition-colors disabled:opacity-50"
              >
                <img
                  v-if="isGroupCompleting(group.name)"
                  src="https://img.icons8.com/doodle/48/watering-can.png"
                  alt=""
                  class="w-4 h-4 animate-bounce"
                >
                <img
                  v-else
                  src="https://img.icons8.com/doodle/48/checkmark--v1.png"
                  alt=""
                  class="w-4 h-4"
                >
                <span>{{ isGroupCompleting(group.name) ? 'Working...' : 'Complete All' }}</span>
              </button>
            </div>
            <div class="space-y-2">
              <TaskItem
                v-for="task in group.tasks"
                :key="task.id"
                :task="task"
                :plant="getPlant(task.plant_id)"
              />
            </div>
          </div>
        </div>
      </section>

      <!-- Completed tasks link -->
      <section v-if="completedTasks.length > 0" class="mt-6">
        <router-link
          to="/tasks/completed"
          class="flex items-center justify-center gap-2 py-3 text-sm text-charcoal-400 hover:text-charcoal-600 transition-colors"
        >
          <img
            src="https://img.icons8.com/doodle/48/checkmark--v1.png"
            alt=""
            class="w-5 h-5 opacity-60"
          >
          <span>{{ completedTasks.length }} task{{ completedTasks.length !== 1 ? 's' : '' }} completed</span>
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </router-link>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'
import { usePlantsStore } from '@/stores/plants'
import TaskItem from '@/components/tasks/TaskItem.vue'

const router = useRouter()
const tasks = useTasksStore()
const plants = usePlantsStore()

onMounted(async () => {
  await Promise.all([
    tasks.fetchTodayTasks(),
    plants.fetchPlants()
  ])
})

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
})

const pendingTasks = computed(() => tasks.todayTasks.filter(t => !t.completed_at))
const completedTasks = computed(() => tasks.todayTasks.filter(t => t.completed_at))

function getPlant(plantId) {
  return plants.getPlantById(plantId)
}
</script>

<template>
  <div class="page-container">
    <header class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">{{ greeting }}</h1>
      <p class="text-gray-500 mt-1">
        <span v-if="tasks.todayCount > 0">
          You have {{ tasks.todayCount }} task{{ tasks.todayCount !== 1 ? 's' : '' }} today
        </span>
        <span v-else>
          All caught up! Your plants are happy.
        </span>
      </p>
    </header>

    <!-- Loading state -->
    <div v-if="tasks.loading" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="tasks.todayTasks.length === 0" class="text-center py-12">
      <div class="w-20 h-20 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
        <svg class="w-10 h-10 text-plant-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
      </div>
      <h2 class="text-lg font-semibold text-gray-900 mb-2">No tasks today</h2>
      <p class="text-gray-500 mb-6">Add some plants to get personalized care tasks</p>
      <button @click="router.push('/plants/add')" class="btn-primary">
        Add Your First Plant
      </button>
    </div>

    <!-- Task list -->
    <div v-else class="space-y-6">
      <!-- Pending tasks -->
      <section v-if="pendingTasks.length > 0">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">To Do</h2>
        <div class="space-y-3">
          <TaskItem
            v-for="task in pendingTasks"
            :key="task.id"
            :task="task"
            :plant="getPlant(task.plant_id)"
          />
        </div>
      </section>

      <!-- Completed tasks -->
      <section v-if="completedTasks.length > 0">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
          Completed ({{ completedTasks.length }})
        </h2>
        <div class="space-y-3 opacity-60">
          <TaskItem
            v-for="task in completedTasks"
            :key="task.id"
            :task="task"
            :plant="getPlant(task.plant_id)"
            completed
          />
        </div>
      </section>
    </div>
  </div>
</template>

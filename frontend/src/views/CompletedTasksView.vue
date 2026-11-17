<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'
import { usePlantsStore } from '@/stores/plants'
import TaskItem from '@/components/tasks/TaskItem.vue'

const router = useRouter()
const tasks = useTasksStore()
const plants = usePlantsStore()

const loading = ref(true)

// Live from the store, so tapping a check mark to undo removes it here.
const completedTasks = computed(() => tasks.todayTasks.filter(t => t.completed_at))

onMounted(async () => {
  await Promise.all([
    tasks.fetchTodayTasks(),
    plants.plants.length ? Promise.resolve() : plants.fetchPlants()
  ])
  loading.value = false
})

function getPlant(plantId) {
  return plants.getPlantById(plantId)
}

const todayLabel = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })
</script>

<template>
  <div class="page-container">
    <header class="flex items-center gap-4 mb-6">
      <button type="button" @click="router.back()" class="btn-ghost p-2 -ml-2" aria-label="Go back">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </button>
      <div>
        <h1 class="page-title mb-0">Done today</h1>
        <p class="text-sm text-charcoal-500">{{ todayLabel }}</p>
      </div>
    </header>

    <!-- Loading -->
    <div v-if="loading" class="flex flex-col items-center justify-center py-12" role="status">
      <img src="https://img.icons8.com/doodle/96/watering-can.png" alt="" class="w-16 h-16 loading-watering-can">
      <p class="text-charcoal-500 mt-4 font-hand text-xl">Loading tasks...</p>
    </div>

    <!-- Error -->
    <div v-else-if="tasks.error && completedTasks.length === 0" class="card p-6 text-center" role="alert">
      <p class="text-charcoal-600 mb-4">{{ tasks.error }}</p>
      <button type="button" @click="tasks.fetchTodayTasks()" class="btn-primary">Try again</button>
    </div>

    <!-- Empty state -->
    <div v-else-if="completedTasks.length === 0" class="text-center py-12">
      <div class="w-24 h-24 mx-auto mb-4 bg-cream-100 rounded-3xl flex items-center justify-center">
        <img src="https://img.icons8.com/doodle/96/checkmark--v1.png" alt="" class="w-14 h-14 opacity-50">
      </div>
      <h2 class="font-hand text-2xl text-charcoal-600 mb-2">Nothing done yet today</h2>
      <p class="text-charcoal-500 mb-6">Tasks you finish today show up here.</p>
      <button type="button" @click="router.push('/')" class="btn-primary">
        Back to Today
      </button>
    </div>

    <!-- Completed tasks list -->
    <template v-else>
      <p class="text-sm text-charcoal-500 mb-3">Tap a check mark to put a task back on your list.</p>
      <div class="space-y-3">
        <TaskItem
          v-for="task in completedTasks"
          :key="task.id"
          :task="task"
          :plant="getPlant(task.plant_id)"
          :show-recommendations="false"
        />
      </div>
      <p class="text-center text-sm text-charcoal-500 mt-8">
        {{ completedTasks.length }} task{{ completedTasks.length !== 1 ? 's' : '' }} done today
      </p>
    </template>
  </div>
</template>

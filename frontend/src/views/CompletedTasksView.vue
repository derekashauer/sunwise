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
const completedTasks = ref([])

onMounted(async () => {
  await Promise.all([
    tasks.fetchTodayTasks(),
    plants.fetchPlants()
  ])
  completedTasks.value = tasks.todayTasks.filter(t => t.completed_at)
  loading.value = false
})

function getPlant(plantId) {
  return plants.getPlantById(plantId)
}
</script>

<template>
  <div class="page-container">
    <header class="flex items-center gap-4 mb-6">
      <button @click="router.back()" class="btn-ghost p-2 -ml-2">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </button>
      <div>
        <h1 class="page-title mb-0">Completed Today</h1>
        <p class="text-sm text-charcoal-400">{{ new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) }}</p>
      </div>
    </header>

    <!-- Loading -->
    <div v-if="loading" class="flex flex-col items-center justify-center py-12">
      <img
        src="https://img.icons8.com/doodle/96/watering-can.png"
        alt="loading"
        class="w-16 h-16 loading-watering-can"
      >
      <p class="text-charcoal-400 mt-4 font-hand text-xl">Loading tasks...</p>
    </div>

    <!-- Empty state -->
    <div v-else-if="completedTasks.length === 0" class="text-center py-12">
      <div class="w-24 h-24 mx-auto mb-4 bg-cream-100 rounded-3xl flex items-center justify-center">
        <img
          src="https://img.icons8.com/doodle/96/checkmark--v1.png"
          alt="no tasks"
          class="w-14 h-14 opacity-50"
        >
      </div>
      <h2 class="font-hand text-2xl text-charcoal-600 mb-2">No completed tasks yet</h2>
      <p class="text-charcoal-400 mb-6">Complete some tasks and they'll show up here</p>
      <button @click="router.push('/')" class="btn-primary">
        Back to Today
      </button>
    </div>

    <!-- Completed tasks list -->
    <div v-else class="space-y-3">
      <TaskItem
        v-for="task in completedTasks"
        :key="task.id"
        :task="task"
        :plant="getPlant(task.plant_id)"
        completed
        :show-recommendations="false"
      />
    </div>

    <p v-if="completedTasks.length > 0" class="text-center text-sm text-charcoal-400 mt-8">
      {{ completedTasks.length }} task{{ completedTasks.length !== 1 ? 's' : '' }} completed
    </p>
  </div>
</template>

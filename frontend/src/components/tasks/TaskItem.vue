<script setup>
import { ref } from 'vue'
import { useTasksStore } from '@/stores/tasks'

const props = defineProps({
  task: { type: Object, required: true },
  plant: { type: Object, default: null },
  completed: { type: Boolean, default: false }
})

const tasks = useTasksStore()
const loading = ref(false)

const taskIcons = {
  water: '💧',
  fertilize: '🌱',
  trim: '✂️',
  repot: '🪴',
  rotate: '🔄',
  mist: '💨',
  check: '👀'
}

async function complete() {
  if (loading.value || props.completed) return
  loading.value = true
  try {
    await tasks.completeTask(props.task.id)
    window.$toast?.success('Task completed!')
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div
    class="card p-4 flex items-start gap-4"
    :class="{ 'opacity-60': completed }"
  >
    <!-- Checkbox -->
    <button
      @click="complete"
      :disabled="loading || completed"
      class="w-6 h-6 rounded-full border-2 flex-shrink-0 flex items-center justify-center transition-all"
      :class="completed
        ? 'bg-plant-500 border-plant-500'
        : 'border-gray-300 hover:border-plant-500'"
    >
      <svg v-if="completed" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
      <div v-else-if="loading" class="w-4 h-4 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </button>

    <!-- Content -->
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2 mb-1">
        <span class="text-lg">{{ taskIcons[task.task_type] || '📋' }}</span>
        <span class="font-medium text-gray-900 capitalize">{{ task.task_type }}</span>
        <span
          v-if="task.priority === 'high' || task.priority === 'urgent'"
          class="px-2 py-0.5 text-xs font-medium rounded-full"
          :class="task.priority === 'urgent' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'"
        >
          {{ task.priority }}
        </span>
      </div>

      <p v-if="plant" class="text-sm text-gray-500 truncate">
        {{ plant.name }}
        <span v-if="plant.location" class="text-gray-400">· {{ plant.location }}</span>
      </p>

      <p v-if="task.instructions" class="text-sm text-gray-600 mt-2">
        {{ task.instructions }}
      </p>
    </div>

    <!-- Plant thumbnail -->
    <div v-if="plant?.thumbnail" class="w-12 h-12 rounded-lg overflow-hidden flex-shrink-0">
      <img :src="`/uploads/plants/${plant.thumbnail}`" :alt="plant.name" class="w-full h-full object-cover">
    </div>
  </div>
</template>

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'

export const useTasksStore = defineStore('tasks', () => {
  const todayTasks = ref([])
  const upcomingTasks = ref([])
  const loading = ref(false)
  const error = ref(null)

  const api = useApi()

  const todayCount = computed(() => todayTasks.value.filter(t => !t.completed_at).length)
  const completedTodayCount = computed(() => todayTasks.value.filter(t => t.completed_at).length)

  async function fetchTodayTasks() {
    loading.value = true
    error.value = null
    try {
      const response = await api.get('/tasks/today')
      todayTasks.value = response.tasks
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function fetchUpcomingTasks() {
    loading.value = true
    error.value = null
    try {
      const response = await api.get('/tasks/upcoming')
      upcomingTasks.value = response.tasks
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function completeTask(taskId, notes = null) {
    const response = await api.post(`/tasks/${taskId}/complete`, { notes })

    // Update local state
    const updateTask = (tasks) => {
      const index = tasks.findIndex(t => t.id === taskId)
      if (index !== -1) {
        tasks[index] = response.task
      }
    }

    updateTask(todayTasks.value)
    updateTask(upcomingTasks.value)

    return response.task
  }

  async function skipTask(taskId, reason) {
    const response = await api.post(`/tasks/${taskId}/skip`, { reason })

    // Remove from lists
    todayTasks.value = todayTasks.value.filter(t => t.id !== taskId)
    upcomingTasks.value = upcomingTasks.value.filter(t => t.id !== taskId)

    return response.task
  }

  async function getPlantTasks(plantId) {
    const response = await api.get(`/tasks/plant/${plantId}`)
    return response.tasks
  }

  return {
    todayTasks,
    upcomingTasks,
    loading,
    error,
    todayCount,
    completedTodayCount,
    fetchTodayTasks,
    fetchUpcomingTasks,
    completeTask,
    skipTask,
    getPlantTasks
  }
})

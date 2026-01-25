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

  async function completeCheckTask(taskId, checkData) {
    // Build a summary note from check data for backwards compatibility
    const summaryParts = []
    if (checkData.moisture_level) {
      const moistureLabel = checkData.moisture_level <= 3 ? 'dry' : checkData.moisture_level <= 7 ? 'moist' : 'wet'
      summaryParts.push(`Moisture: ${checkData.moisture_level}/10 (${moistureLabel})`)
    }
    if (checkData.light_reading) {
      summaryParts.push(`Light: ${checkData.light_reading} fc`)
    }
    if (checkData.general_health) {
      summaryParts.push(`Health: ${checkData.general_health}/5`)
    }
    const observations = []
    if (checkData.new_growth) observations.push('new growth')
    if (checkData.yellowing_leaves) observations.push('yellowing')
    if (checkData.brown_tips) observations.push('brown tips')
    if (checkData.pests_observed) observations.push('pests')
    if (checkData.dusty_dirty) observations.push('needs cleaning')
    if (observations.length) {
      summaryParts.push(`Observed: ${observations.join(', ')}`)
    }
    if (checkData.notes) {
      summaryParts.push(checkData.notes)
    }

    const response = await api.post(`/tasks/${taskId}/complete`, {
      notes: summaryParts.join('. '),
      check_data: checkData
    })

    // Update local state
    const updateTask = (tasks) => {
      const index = tasks.findIndex(t => t.id === taskId)
      if (index !== -1) {
        tasks[index] = response.task
      }
    }

    updateTask(todayTasks.value)
    updateTask(upcomingTasks.value)

    // Return both task and insights
    return {
      task: response.task,
      insights: response.insights || []
    }
  }

  async function skipTask(taskId, reason) {
    const response = await api.post(`/tasks/${taskId}/skip`, { reason })

    // Remove from lists
    todayTasks.value = todayTasks.value.filter(t => t.id !== taskId)
    upcomingTasks.value = upcomingTasks.value.filter(t => t.id !== taskId)

    return response.task
  }

  async function bulkCompleteTasks(taskIds, notes = null) {
    const response = await api.post('/tasks/bulk-complete', { task_ids: taskIds, notes })

    // Update local state - mark completed tasks
    const now = new Date().toISOString()
    for (const taskId of response.completed) {
      const index = todayTasks.value.findIndex(t => t.id === taskId)
      if (index !== -1) {
        todayTasks.value[index].completed_at = now
      }
    }

    return response
  }

  async function bulkSkipTasks(taskIds, reason = null) {
    const response = await api.post('/tasks/bulk-skip', { task_ids: taskIds, reason })

    // Update local state - remove skipped tasks from lists
    for (const taskId of response.skipped) {
      todayTasks.value = todayTasks.value.filter(t => t.id !== taskId)
      upcomingTasks.value = upcomingTasks.value.filter(t => t.id !== taskId)
    }

    return response
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
    completeCheckTask,
    skipTask,
    bulkCompleteTasks,
    bulkSkipTasks,
    getPlantTasks
  }
})

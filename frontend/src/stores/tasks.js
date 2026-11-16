import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'
import { enqueue, removeEntry, flushOutbox, countOutbox, isOfflineError } from '@/services/outbox'
import { todayLocal } from '@/utils/dates'

// A UTC timestamp in the same shape the API returns ("YYYY-MM-DD HH:MM:SS").
function nowUtc() {
  return new Date().toISOString().slice(0, 19).replace('T', ' ')
}

export const useTasksStore = defineStore('tasks', () => {
  const todayTasks = ref([])
  const upcomingTasks = ref([])
  const loadingToday = ref(false)
  const loadingUpcoming = ref(false)
  const errorToday = ref(null)
  const errorUpcoming = ref(null)
  // "Today" as the server sees it, in the user's own time zone.
  const today = ref(null)
  const pendingSync = ref(0)

  const api = useApi()

  const todayCount = computed(() => todayTasks.value.filter(t => !t.completed_at).length)
  const completedTodayCount = computed(() => todayTasks.value.filter(t => t.completed_at).length)

  function findTask(taskId) {
    return todayTasks.value.find(t => t.id === taskId) ||
      upcomingTasks.value.find(t => t.id === taskId) ||
      null
  }

  // Merge new fields into a task in both lists. The API's task rows don't
  // carry plant name or thumbnail, so keep what the list already had.
  function patchTask(taskId, changes) {
    for (const list of [todayTasks, upcomingTasks]) {
      const index = list.value.findIndex(t => t.id === taskId)
      if (index !== -1) {
        list.value[index] = { ...list.value[index], ...changes }
      }
    }
  }

  function removeTask(taskId) {
    todayTasks.value = todayTasks.value.filter(t => t.id !== taskId)
    upcomingTasks.value = upcomingTasks.value.filter(t => t.id !== taskId)
  }

  async function refreshPendingSync() {
    pendingSync.value = await countOutbox()
  }

  /**
   * Run an action now, or queue it when there's no connection.
   * Returns { response, queued, outboxId }.
   */
  async function runOrQueue({ kind, taskId, endpoint, body }) {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      const outboxId = await enqueue({ kind, taskId, endpoint, body })
      if (outboxId === null) throw new Error("Can't reach Sunwise, and this device can't save it for later.")
      await refreshPendingSync()
      return { response: null, queued: true, outboxId }
    }
    try {
      const response = await api.post(endpoint, body)
      return { response, queued: false, outboxId: null }
    } catch (e) {
      if (!isOfflineError(e)) throw e
      const outboxId = await enqueue({ kind, taskId, endpoint, body })
      if (outboxId === null) throw e
      await refreshPendingSync()
      return { response: null, queued: true, outboxId }
    }
  }

  async function fetchTodayTasks() {
    loadingToday.value = true
    errorToday.value = null
    try {
      const response = await api.get('/tasks/today')
      todayTasks.value = response.tasks || []
      today.value = response.today || todayLocal()
    } catch (e) {
      errorToday.value = e.message
    } finally {
      loadingToday.value = false
    }
  }

  async function fetchUpcomingTasks() {
    loadingUpcoming.value = true
    errorUpcoming.value = null
    try {
      const response = await api.get('/tasks/upcoming')
      upcomingTasks.value = response.tasks || []
    } catch (e) {
      errorUpcoming.value = e.message
    } finally {
      loadingUpcoming.value = false
    }
  }

  /** Complete a task. Returns { task, queued }. */
  async function completeTask(taskId, notes = null) {
    const { response, queued, outboxId } = await runOrQueue({
      kind: 'complete',
      taskId,
      endpoint: `/tasks/${taskId}/complete`,
      body: { notes }
    })

    if (queued) {
      patchTask(taskId, { completed_at: nowUtc(), notes, _outboxId: outboxId })
      return { task: findTask(taskId), queued: true }
    }

    patchTask(taskId, { ...response.task, _outboxId: null })
    return { task: findTask(taskId) || response.task, queued: false }
  }

  /** Put a completed task back to pending. */
  async function uncompleteTask(taskId) {
    const task = findTask(taskId)

    // Still waiting in the outbox: just cancel it.
    if (task?._outboxId) {
      await removeEntry(task._outboxId)
      await refreshPendingSync()
      patchTask(taskId, { completed_at: null, completed_by_name: null, notes: null, _outboxId: null })
      return findTask(taskId)
    }

    const response = await api.post(`/tasks/${taskId}/uncomplete`)
    patchTask(taskId, { ...response.task, completed_at: null, completed_by_name: null })
    return findTask(taskId) || response.task
  }

  function buildCheckNotes(checkData) {
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
    return summaryParts.join('. ')
  }

  /** Complete a check task with its structured data. Returns { task, insights, queued }. */
  async function completeCheckTask(taskId, checkData) {
    const notes = buildCheckNotes(checkData)
    const { response, queued, outboxId } = await runOrQueue({
      kind: 'complete',
      taskId,
      endpoint: `/tasks/${taskId}/complete`,
      body: { notes, check_data: checkData }
    })

    if (queued) {
      patchTask(taskId, { completed_at: nowUtc(), notes, _outboxId: outboxId })
      return { task: findTask(taskId), insights: [], queued: true }
    }

    patchTask(taskId, { ...response.task, _outboxId: null })
    return {
      task: findTask(taskId) || response.task,
      insights: response.insights || [],
      carePlanUpdated: !!response.care_plan_updated,
      queued: false
    }
  }

  /** Skip a task. Returns { queued }. */
  async function skipTask(taskId, reason = null) {
    const { queued } = await runOrQueue({
      kind: 'skip',
      taskId,
      endpoint: `/tasks/${taskId}/skip`,
      body: { reason }
    })
    removeTask(taskId)
    return { queued }
  }

  /**
   * Move a task's due date without logging a skip.
   * Pass { days } (1-30) or { date: 'YYYY-MM-DD' }. Returns { task, queued }.
   */
  async function snoozeTask(taskId, { days = null, date = null } = {}) {
    const body = date ? { date } : { days }
    const { response, queued } = await runOrQueue({
      kind: 'snooze',
      taskId,
      endpoint: `/tasks/${taskId}/snooze`,
      body
    })
    // A snoozed task is no longer due today.
    todayTasks.value = todayTasks.value.filter(t => t.id !== taskId)
    if (response?.task) {
      patchTask(taskId, response.task)
    }
    return { task: response?.task || null, queued }
  }

  /**
   * Complete several tasks at once. Check tasks are never included: their
   * moisture and health readings must go through the check form.
   */
  async function bulkCompleteTasks(taskIds, notes = null) {
    const response = await api.post('/tasks/bulk-complete', { task_ids: taskIds, notes })
    const now = nowUtc()
    for (const taskId of response.completed || []) {
      patchTask(taskId, { completed_at: now })
    }
    return response
  }

  async function bulkUncompleteTasks(taskIds) {
    const response = await api.post('/tasks/bulk-uncomplete', { task_ids: taskIds })
    for (const taskId of response.uncompleted || []) {
      patchTask(taskId, { completed_at: null, completed_by_name: null })
    }
    return response
  }

  async function bulkSkipTasks(taskIds, reason = null) {
    const response = await api.post('/tasks/bulk-skip', { task_ids: taskIds, reason })
    for (const taskId of response.skipped || []) {
      removeTask(taskId)
    }
    return response
  }

  async function getPlantTasks(plantId) {
    const response = await api.get(`/tasks/plant/${plantId}`)
    return response.tasks
  }

  /**
   * Send anything queued while offline, then reload Today so the list matches
   * the server. Returns the flush result.
   */
  async function syncOutbox() {
    const result = await flushOutbox(api)
    await refreshPendingSync()
    if (result.sent > 0 || result.failed > 0) {
      await fetchTodayTasks()
      if (result.failed > 0) {
        window.$toast?.info(`${result.failed} offline change${result.failed === 1 ? '' : 's'} couldn't be saved. The list is up to date now.`)
      } else {
        window.$toast?.success(`Synced ${result.sent} offline change${result.sent === 1 ? '' : 's'}`)
      }
    }
    return result
  }

  return {
    todayTasks,
    upcomingTasks,
    // `loading` and `error` describe the Today list, which most screens use.
    loading: loadingToday,
    error: errorToday,
    loadingToday,
    loadingUpcoming,
    errorToday,
    errorUpcoming,
    today,
    pendingSync,
    todayCount,
    completedTodayCount,
    findTask,
    patchTask,
    removeTask,
    fetchTodayTasks,
    fetchUpcomingTasks,
    completeTask,
    uncompleteTask,
    completeCheckTask,
    skipTask,
    snoozeTask,
    bulkCompleteTasks,
    bulkUncompleteTasks,
    bulkSkipTasks,
    getPlantTasks,
    syncOutbox,
    refreshPendingSync
  }
})

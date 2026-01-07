<script setup>
import { computed } from 'vue'

const props = defineProps({
  entry: { type: Object, required: true },
  actionTypes: { type: Object, default: () => ({ preset: [], custom: [] }) }
})

const allActions = computed(() => [
  ...(props.actionTypes.preset || []),
  ...(props.actionTypes.custom || [])
])

function getActionIcon(action) {
  const found = allActions.value.find(a => a.value === action)
  return found?.icon || '📝'
}

function getActionLabel(action) {
  const found = allActions.value.find(a => a.value === action)
  return found?.label || action
}

function formatDate(dateStr) {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays < 7) return `${diffDays}d ago`

  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const photoUrl = computed(() => {
  if (props.entry.photo_thumbnail) {
    return `/uploads/plants/${props.entry.photo_thumbnail}`
  }
  return null
})
</script>

<template>
  <div class="flex gap-3 py-3">
    <!-- Icon -->
    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-plant-100 flex items-center justify-center text-lg">
      {{ getActionIcon(entry.action) }}
    </div>

    <!-- Content -->
    <div class="flex-1 min-w-0">
      <div class="flex items-start justify-between gap-2">
        <div>
          <span class="font-medium text-gray-900">{{ getActionLabel(entry.action) }}</span>
          <span v-if="entry.task_id" class="ml-1.5 text-xs text-plant-600 bg-plant-50 px-1.5 py-0.5 rounded">
            Task
          </span>
          <span v-if="entry.performed_by_name" class="ml-1.5 text-xs text-gray-500">
            by {{ entry.performed_by_name }}
          </span>
        </div>
        <span class="text-xs text-gray-500 flex-shrink-0">{{ formatDate(entry.performed_at) }}</span>
      </div>

      <!-- Notes -->
      <p v-if="entry.notes" class="text-sm text-gray-600 mt-1">{{ entry.notes }}</p>

      <!-- Photo thumbnail -->
      <div v-if="photoUrl" class="mt-2">
        <img
          :src="photoUrl"
          class="h-20 w-20 object-cover rounded-lg border border-gray-200"
          alt="Care photo"
        >
      </div>

      <!-- Outcome badge for task completions -->
      <div v-if="entry.outcome" class="mt-1">
        <span
          class="text-xs px-2 py-0.5 rounded-full"
          :class="entry.outcome === 'completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'"
        >
          {{ entry.outcome === 'completed' ? 'Completed' : 'Skipped' }}
        </span>
      </div>
    </div>
  </div>
</template>

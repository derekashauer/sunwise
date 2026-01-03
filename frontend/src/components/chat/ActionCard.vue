<script setup>
import { computed } from 'vue'

const props = defineProps({
  action: { type: Object, required: true }
})

const emit = defineEmits(['apply', 'dismiss'])

const actionLabel = computed(() => {
  switch (props.action.type) {
    case 'update_species':
      return 'Update Species'
    case 'update_care_schedule':
      return 'Update Care Schedule'
    case 'update_notes':
      return 'Add Note'
    case 'update_health':
      return 'Update Health Status'
    default:
      return 'Suggested Update'
  }
})

const actionIcon = computed(() => {
  switch (props.action.type) {
    case 'update_species':
      return 'M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8'
    case 'update_care_schedule':
      return 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'
    case 'update_notes':
      return 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'
    case 'update_health':
      return 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z'
    default:
      return 'M13 10V3L4 14h7v7l9-11h-7z'
  }
})
</script>

<template>
  <div class="bg-plant-50 border border-plant-200 rounded-xl p-3">
    <div class="flex items-start gap-3">
      <div class="w-8 h-8 rounded-full bg-plant-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="actionIcon" />
        </svg>
      </div>

      <div class="flex-1 min-w-0">
        <p class="font-medium text-plant-900 text-sm">{{ actionLabel }}</p>

        <div v-if="action.current && action.new" class="mt-1 text-sm">
          <span class="text-gray-500 line-through">{{ action.current }}</span>
          <span class="mx-2 text-gray-400">→</span>
          <span class="text-plant-700 font-medium">{{ action.new }}</span>
        </div>
        <p v-else-if="action.new" class="mt-1 text-sm text-plant-700">
          {{ action.new }}
        </p>

        <p v-if="action.reason" class="mt-1 text-xs text-gray-500">
          {{ action.reason }}
        </p>

        <div class="flex gap-2 mt-3">
          <button
            @click="emit('apply')"
            class="btn-primary text-sm px-3 py-1.5"
          >
            Apply
          </button>
          <button
            @click="emit('dismiss')"
            class="btn-secondary text-sm px-3 py-1.5"
          >
            Dismiss
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

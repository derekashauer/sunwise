<script setup>
// Skip a task, with an optional reason and an optional AI schedule change.
import { ref, computed, watch } from 'vue'
import { useTasksStore } from '@/stores/tasks'
import { useApi } from '@/composables/useApi'
import BaseModal from '@/components/common/BaseModal.vue'
import { getTaskIcon, getTaskLabel } from '@/constants/taskTypes'

const props = defineProps({
  task: { type: Object, required: true },
  plantName: { type: String, default: '' },
  visible: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'skipped'])

const tasks = useTasksStore()
const api = useApi()

const skipReasons = [
  { value: 'still_moist', label: 'Soil is still moist', icon: '💧' },
  { value: 'plant_away', label: 'Plant is away or outdoors', icon: '🌳' },
  { value: 'recently_done', label: 'Already did this recently', icon: '✅' },
  { value: 'plant_stressed', label: 'Plant seems stressed', icon: '😟' },
  { value: 'weather', label: 'Weather conditions', icon: '🌧️' },
  { value: 'other', label: 'Other reason', icon: '📝' }
]

const skipReason = ref('')
const customSkipReason = ref('')
const skipping = ref(false)
const adjustingSchedule = ref(false)
const scheduleAdjustment = ref(null)

const radioName = `skip-reason-${props.task.id}`

watch(() => props.visible, (visible) => {
  if (visible) {
    skipReason.value = ''
    customSkipReason.value = ''
    scheduleAdjustment.value = null
  }
})

const needsCustomText = computed(() => skipReason.value === 'other' && !customSkipReason.value.trim())

function getSkipReasonText() {
  if (skipReason.value === 'other') {
    return customSkipReason.value.trim()
  }
  const reason = skipReasons.find(r => r.value === skipReason.value)
  return reason ? reason.label : skipReason.value
}

async function skipWithReason() {
  if (needsCustomText.value) return

  skipping.value = true
  try {
    const reason = skipReason.value ? getSkipReasonText() : null
    const { queued } = await tasks.skipTask(props.task.id, reason)
    if (queued) {
      window.$toast?.info('Skipped. Saved offline, will sync.')
    } else {
      window.$toast?.success(`Skipped ${getTaskLabel(props.task.task_type).toLowerCase()} for ${props.plantName}`)
    }
    emit('skipped', { task: props.task, adjusted: false })
    emit('close')
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    skipping.value = false
  }
}

async function getAIScheduleAdjustment() {
  if (!skipReason.value || needsCustomText.value) return

  adjustingSchedule.value = true
  try {
    scheduleAdjustment.value = await api.post(`/tasks/${props.task.id}/adjust-schedule`, {
      reason: getSkipReasonText()
    }, { timeout: 45000 })
  } catch (error) {
    window.$toast?.error(error.message || "Couldn't get a schedule suggestion.")
  } finally {
    adjustingSchedule.value = false
  }
}

async function applyScheduleAdjustment() {
  if (!scheduleAdjustment.value) return

  skipping.value = true
  try {
    await api.post(`/tasks/${props.task.id}/apply-adjustment`, {
      reason: getSkipReasonText(),
      adjustment: scheduleAdjustment.value.adjustment
    })
    // The task was skipped as part of the change. Drop just this one task
    // instead of reloading the whole list.
    tasks.removeTask(props.task.id)
    const days = scheduleAdjustment.value.new_interval
    window.$toast?.success(days ? `Schedule changed to every ${days} days` : 'Schedule updated')
    emit('skipped', { task: props.task, adjusted: true })
    emit('close')
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    skipping.value = false
  }
}
</script>

<template>
  <BaseModal
    :visible="visible"
    title="Skip task"
    close-label="Close skip dialog"
    @close="emit('close')"
  >
    <template #header>
      <div class="min-w-0">
        <h2 class="font-semibold text-lg text-charcoal-700">Skip task</h2>
        <p class="text-sm text-charcoal-500 mt-0.5 flex items-center gap-1">
          <img :src="getTaskIcon(task.task_type)" alt="" class="w-4 h-4 inline">
          <span>{{ getTaskLabel(task.task_type) }} · {{ plantName }}</span>
        </p>
      </div>
    </template>

    <div class="space-y-4">
      <fieldset>
        <legend class="text-sm font-medium text-charcoal-600 mb-2">Why are you skipping? (optional)</legend>
        <div class="space-y-2">
          <label
            v-for="reason in skipReasons"
            :key="reason.value"
            class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all focus-within:ring-2 focus-within:ring-sage-600 focus-within:ring-offset-1"
            :class="skipReason === reason.value
              ? 'border-plant-600 bg-plant-50'
              : 'border-gray-200 hover:border-gray-300'"
          >
            <input
              type="radio"
              :name="radioName"
              v-model="skipReason"
              :value="reason.value"
              class="sr-only"
            >
            <span class="text-lg" aria-hidden="true">{{ reason.icon }}</span>
            <span class="text-sm text-charcoal-600">{{ reason.label }}</span>
          </label>
        </div>
      </fieldset>

      <div v-if="skipReason === 'other'">
        <label :for="`${radioName}-other`" class="sr-only">Describe why you're skipping</label>
        <textarea
          :id="`${radioName}-other`"
          v-model="customSkipReason"
          rows="2"
          class="input text-sm"
          placeholder="Describe why you're skipping..."
        ></textarea>
      </div>

      <!-- AI schedule change -->
      <div v-if="skipReason && !scheduleAdjustment" class="pt-2 border-t">
        <button
          type="button"
          @click="getAIScheduleAdjustment"
          :disabled="adjustingSchedule || needsCustomText"
          class="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl border-2 border-dashed border-plant-400 text-plant-700 hover:bg-plant-50 transition-colors disabled:opacity-50"
        >
          <span v-if="adjustingSchedule" class="w-5 h-5 border-2 border-plant-600 border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
          <span class="text-sm font-medium">
            {{ adjustingSchedule ? 'Thinking...' : 'Suggest a better schedule' }}
          </span>
        </button>
        <p class="text-xs text-charcoal-500 text-center mt-2">
          Uses your reason to suggest how often this task should happen
        </p>
      </div>

      <div v-if="scheduleAdjustment" class="bg-plant-50 rounded-xl p-4 border border-plant-200" aria-live="polite">
        <p class="font-medium text-plant-900 text-sm">Suggestion</p>
        <p class="text-sm text-plant-800 mt-1">{{ scheduleAdjustment.suggestion }}</p>
        <p v-if="scheduleAdjustment.new_interval" class="text-xs text-plant-800 mt-2 font-semibold">
          New schedule: every {{ scheduleAdjustment.new_interval }} days
        </p>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          type="button"
          @click="skipWithReason"
          :disabled="skipping || needsCustomText"
          class="flex-1 btn-secondary"
        >
          <span v-if="skipping" class="w-4 h-4 border-2 border-gray-400 border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
          <span v-else>{{ scheduleAdjustment ? 'Just skip' : 'Skip' }}</span>
        </button>
        <button
          v-if="scheduleAdjustment"
          type="button"
          @click="applyScheduleAdjustment"
          :disabled="skipping"
          class="flex-1 btn-primary"
        >
          Skip and update schedule
        </button>
      </div>
    </template>
  </BaseModal>
</template>

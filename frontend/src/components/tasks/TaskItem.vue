<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'
import CareInfoModal from '@/components/plants/CareInfoModal.vue'
import CheckTaskModal from '@/components/tasks/CheckTaskModal.vue'
import SkipTaskModal from '@/components/tasks/SkipTaskModal.vue'
import TaskRecommendations from '@/components/tasks/TaskRecommendations.vue'
import ImageLightbox from '@/components/common/ImageLightbox.vue'
import { getTaskIcon, getTaskLabel, getTaskInterval } from '@/constants/taskTypes'
import {
  parseUtc,
  parseLocalDate,
  daysFromToday,
  formatShortDate,
  formatRelativeDays,
  formatDaysLate,
  toLocalDateInput,
  addDays
} from '@/utils/dates'

const router = useRouter()

const props = defineProps({
  task: { type: Object, required: true },
  plant: { type: Object, default: null },
  completed: { type: Boolean, default: false },
  showRecommendations: { type: Boolean, default: true },
  // Show which room the plant is in. Used when the list isn't already grouped
  // by location, so a location-ordered list can still be read room by room.
  showLocation: { type: Boolean, default: false }
})

// Lets parents that keep their own task list (like the plant page) stay in
// sync. The Today list lives in the store and updates itself.
const emit = defineEmits(['changed', 'removed'])

const tasks = useTasksStore()

const loading = ref(false)
const expanded = ref(false)
const notes = ref('')
const showSnooze = ref(false)
const snoozeDate = ref('')
const snoozing = ref(false)

const showSkipModal = ref(false)
const showImageModal = ref(false)
const showCareInfoModal = ref(false)
const showCheckModal = ref(false)
const savingCheck = ref(false)

const plantName = computed(() => props.plant?.name || props.task.plant_name || 'Plant')
const thumbnail = computed(() => props.plant?.thumbnail || props.task.plant_thumbnail)
const taskLabel = computed(() => getTaskLabel(props.task.task_type))
const taskIcon = computed(() => getTaskIcon(props.task.task_type))
const isCheck = computed(() => props.task.task_type === 'check')
const isDone = computed(() => props.completed || !!props.task.completed_at)

const locationLabel = computed(
  () => props.plant?.location_name || props.task.plant_location || 'No Location'
)

const daysLate = computed(() => {
  if (isDone.value) return 0
  if (props.task.days_overdue !== undefined && props.task.days_overdue !== null) {
    return Number(props.task.days_overdue) || 0
  }
  const diff = daysFromToday(parseLocalDate(props.task.due_date))
  return diff !== null && diff < 0 ? Math.abs(diff) : 0
})

// "Every 7 days · last done Nov 8 (4 days ago)"
const whyText = computed(() => {
  const parts = []
  const interval = getTaskInterval(props.task)
  parts.push(interval ? `Every ${interval} day${interval === 1 ? '' : 's'}` : 'One-time task')
  const lastDone = parseUtc(props.task.last_done_at)
  if (lastDone) {
    parts.push(`last done ${formatShortDate(lastDone)} (${formatRelativeDays(lastDone)})`)
  } else {
    parts.push('no record of it being done yet')
  }
  const due = parseLocalDate(props.task.due_date)
  if (due && daysLate.value > 0) {
    parts.push(`was due ${formatShortDate(due)}`)
  }
  return parts.join(' · ')
})

const minSnoozeDate = computed(() => toLocalDateInput(addDays(new Date(), 1)))

const doneLabel = computed(() => {
  const past = {
    water: 'Watered',
    fertilize: 'Fertilized',
    mist: 'Misted',
    rotate: 'Rotated',
    trim: 'Trimmed',
    prune: 'Pruned',
    repot: 'Repotted',
    pot_up: 'Potted up',
    change_water: 'Changed water for',
    check_roots: 'Checked roots on',
    check: 'Checked'
  }
  return past[props.task.task_type] || `Done: ${taskLabel.value.toLowerCase()} for`
})

function goToPlant() {
  const id = props.plant?.id || props.task.plant_id
  if (id) router.push(`/plants/${id}`)
}

function toggleExpand() {
  expanded.value = !expanded.value
  if (expanded.value) showSnooze.value = false
}

function handleCheckboxClick() {
  if (loading.value) return
  if (isDone.value) {
    undoComplete()
    return
  }
  if (isCheck.value) {
    showCheckModal.value = true
    return
  }
  complete()
}

function nowUtcString() {
  return new Date().toISOString().slice(0, 19).replace('T', ' ')
}

async function complete(withNotes = false) {
  if (loading.value || isDone.value) return
  loading.value = true
  const noteText = withNotes && notes.value.trim() ? notes.value.trim() : null
  try {
    const { task, queued } = await tasks.completeTask(props.task.id, noteText)
    notes.value = ''
    expanded.value = false
    emit('changed', { id: props.task.id, changes: task || { completed_at: nowUtcString() } })
    const message = queued
      ? `${doneLabel.value} ${plantName.value}. Saved offline, will sync.`
      : `${doneLabel.value} ${plantName.value}`
    window.$toast?.[queued ? 'info' : 'success'](message, {
      action: { label: 'Undo', onClick: undoComplete }
    })
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    loading.value = false
  }
}

async function undoComplete() {
  loading.value = true
  try {
    const task = await tasks.uncompleteTask(props.task.id)
    emit('changed', { id: props.task.id, changes: task || { completed_at: null } })
    window.$toast?.info(`${taskLabel.value} for ${plantName.value} is back on your list`)
  } catch (error) {
    window.$toast?.error(error.message || "Couldn't undo that. Try again.")
  } finally {
    loading.value = false
  }
}

async function handleCheckComplete(checkData) {
  if (savingCheck.value) return
  savingCheck.value = true
  try {
    const result = await tasks.completeCheckTask(props.task.id, checkData)
    showCheckModal.value = false
    emit('changed', { id: props.task.id, changes: result.task || { completed_at: nowUtcString() } })

    if (result.queued) {
      window.$toast?.info(`Check saved offline for ${plantName.value}. It will sync when you're back online.`, {
        action: { label: 'Undo', onClick: undoComplete }
      })
      return
    }

    window.$toast?.success(`Check saved for ${plantName.value}`, {
      action: { label: 'Undo', onClick: undoComplete }
    })

    // Insights stay up longer so there's time to read them.
    for (const insight of result.insights || []) {
      const text = `${plantName.value}: ${insight.message}`
      if (insight.type === 'warning' || insight.type === 'urgent') {
        window.$toast?.error(text, { duration: 10000 })
      } else {
        window.$toast?.info(text, { duration: 8000 })
      }
    }
  } catch (error) {
    // Keep the form open so nothing that was entered is lost.
    window.$toast?.error(error.message)
  } finally {
    savingCheck.value = false
  }
}

function toggleSnooze() {
  showSnooze.value = !showSnooze.value
  if (showSnooze.value) {
    expanded.value = false
    snoozeDate.value = ''
  }
}

async function snooze(option) {
  if (snoozing.value) return
  const payload = option === 'date' ? { date: snoozeDate.value } : { days: option }
  if (option === 'date' && !snoozeDate.value) return

  snoozing.value = true
  try {
    const { task, queued } = await tasks.snoozeTask(props.task.id, payload)
    showSnooze.value = false
    const newDue = task?.due_date
      ? parseLocalDate(task.due_date)
      : option === 'date' ? parseLocalDate(snoozeDate.value) : addDays(new Date(), option)
    emit('changed', { id: props.task.id, changes: task || { due_date: toLocalDateInput(newDue) } })
    const when = formatRelativeDays(newDue)
    const message = `${taskLabel.value} for ${plantName.value} moved to ${formatShortDate(newDue)} (${when})`
    window.$toast?.[queued ? 'info' : 'success'](queued ? `${message}. Saved offline, will sync.` : message)
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    snoozing.value = false
  }
}

function handleSkipped({ task }) {
  emit('removed', task.id)
}
</script>

<template>
  <div class="bg-cream-50 rounded-2xl border-2 border-sage-100" :class="{ 'opacity-70': isDone }">
    <!-- Main row: checkbox, thumbnail, task info -->
    <div class="flex items-center gap-2 p-3 pb-2">
      <!-- Checkbox: 44px tap area around a 28px circle -->
      <button
        type="button"
        @click="handleCheckboxClick"
        :disabled="loading"
        class="w-11 h-11 -ml-1 flex items-center justify-center flex-shrink-0 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-sage-600"
        :aria-label="isDone
          ? `Mark ${taskLabel.toLowerCase()} for ${plantName} as not done`
          : isCheck
            ? `Check ${plantName}`
            : `Mark ${taskLabel.toLowerCase()} for ${plantName} as done`"
        :aria-pressed="isDone"
      >
        <span class="task-checkbox" :class="{ 'checked': isDone }">
          <svg v-if="isDone && !loading" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
          </svg>
          <span v-else-if="loading" class="w-4 h-4 border-2 border-sage-600 border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
        </span>
      </button>

      <!-- Plant thumbnail (tap to view larger) -->
      <button
        v-if="thumbnail"
        type="button"
        @click="showImageModal = true"
        class="w-20 h-20 sm:w-24 sm:h-24 rounded-xl overflow-hidden flex-shrink-0 border-2 border-sage-200 hover:border-sage-400 transition-colors"
        :aria-label="`View larger photo of ${plantName}`"
      >
        <img :src="`/uploads/plants/${thumbnail}`" :alt="plantName" class="w-full h-full object-cover">
      </button>
      <div v-else class="w-20 h-20 sm:w-24 sm:h-24 rounded-xl bg-cream-200 flex items-center justify-center flex-shrink-0">
        <img :src="taskIcon" alt="" class="w-10 h-10">
      </div>

      <!-- Task info -->
      <div class="flex-1 min-w-0 pl-1">
        <div class="flex items-center gap-1.5 flex-wrap">
          <img :src="taskIcon" alt="" class="w-5 h-5">
          <span class="font-semibold text-charcoal-600 text-sm">{{ taskLabel }}</span>
          <span
            v-if="daysLate > 0"
            class="px-1.5 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800"
          >
            {{ formatDaysLate(daysLate) }}
          </span>
          <span
            v-if="task.priority === 'high' || task.priority === 'urgent'"
            class="px-1.5 py-0.5 text-xs font-medium rounded-full"
            :class="task.priority === 'urgent' ? 'bg-terracotta-100 text-terracotta-800' : 'bg-sunny-100 text-sunny-900'"
          >
            {{ task.priority }}
          </span>
          <span
            v-if="showLocation"
            class="flex items-center gap-1 px-1.5 py-0.5 text-xs font-medium rounded-full bg-sage-100 text-sage-800 flex-shrink-0"
          >
            <img src="https://img.icons8.com/doodle/48/place-marker.png" alt="" class="w-3 h-3">
            {{ locationLabel }}
          </span>
        </div>
        <div class="flex items-center gap-1">
          <button
            type="button"
            @click="goToPlant"
            class="text-sm text-charcoal-500 truncate hover:text-sage-700 hover:underline transition-colors text-left py-1"
          >
            {{ plantName }}
          </button>
          <button
            type="button"
            @click.stop="showCareInfoModal = true"
            class="p-2 -my-1 text-charcoal-400 hover:text-plant-700 transition-colors flex-shrink-0 rounded-lg"
            :aria-label="`Care guide for ${plantName}`"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </button>
        </div>
        <p v-if="task.completed_by_name" class="text-xs text-charcoal-500">
          Completed by {{ task.completed_by_name }}
        </p>
        <p v-if="task._outboxId" class="text-xs text-sunny-900">Waiting to sync</p>
      </div>
    </div>

    <!-- Quick actions: snooze, skip, details -->
    <div v-if="!isDone" class="flex items-center border-t border-cream-200 text-sm">
      <button
        type="button"
        @click="toggleSnooze"
        class="flex-1 flex items-center justify-center gap-1.5 py-2.5 min-h-[44px] text-charcoal-500 hover:text-sage-700 hover:bg-cream-100 font-semibold rounded-bl-2xl"
        :class="{ 'text-sage-700 bg-cream-100': showSnooze }"
        :aria-expanded="showSnooze"
        :aria-controls="`snooze-${task.id}`"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        Snooze
      </button>
      <button
        type="button"
        @click="showSkipModal = true"
        class="flex-1 flex items-center justify-center gap-1.5 py-2.5 min-h-[44px] text-charcoal-500 hover:text-terracotta-700 hover:bg-cream-100 font-semibold border-l border-cream-200"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
        </svg>
        Skip
      </button>
      <button
        v-if="showRecommendations"
        type="button"
        @click="toggleExpand"
        class="flex-1 flex items-center justify-center gap-1.5 py-2.5 min-h-[44px] text-charcoal-500 hover:text-sage-700 hover:bg-cream-100 font-semibold border-l border-cream-200 rounded-br-2xl"
        :class="{ 'text-sage-700 bg-cream-100': expanded }"
        :aria-expanded="expanded"
        :aria-controls="`details-${task.id}`"
      >
        Details
        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </button>
    </div>

    <!-- Snooze options -->
    <div v-if="showSnooze && !isDone" :id="`snooze-${task.id}`" class="px-3 pb-3 pt-1">
      <p class="text-sm text-charcoal-600 mb-2">Move this task to:</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" @click="snooze(1)" :disabled="snoozing" class="toggle-btn min-h-[44px]">Tomorrow</button>
        <button type="button" @click="snooze(3)" :disabled="snoozing" class="toggle-btn min-h-[44px]">In 3 days</button>
        <div class="flex items-center gap-2 flex-1 min-w-[200px]">
          <label :for="`snooze-date-${task.id}`" class="sr-only">Pick a day</label>
          <input
            :id="`snooze-date-${task.id}`"
            v-model="snoozeDate"
            type="date"
            :min="minSnoozeDate"
            class="input py-2 text-sm flex-1"
          >
          <button
            type="button"
            @click="snooze('date')"
            :disabled="snoozing || !snoozeDate"
            class="btn-primary py-2 px-4 text-sm"
          >
            Move
          </button>
        </div>
      </div>
    </div>

    <!-- Details: why it's scheduled, a note, and care tips -->
    <div v-if="expanded && !isDone" :id="`details-${task.id}`" class="px-3 pb-3 pt-2 space-y-3">
      <p class="text-sm text-charcoal-600">
        <span class="font-semibold">Why now:</span> {{ whyText }}
      </p>
      <p v-if="task.instructions" class="text-sm text-charcoal-600">
        <span class="font-semibold">How:</span> {{ task.instructions }}
      </p>

      <div class="bg-sage-50 rounded-xl p-3 border border-sage-200">
        <label :for="`note-${task.id}`" class="text-sm text-charcoal-600 mb-2 block">Add a note and mark done (optional)</label>
        <textarea
          :id="`note-${task.id}`"
          v-model="notes"
          rows="2"
          class="input text-sm resize-none mb-2"
          placeholder="e.g., Used 1 cup of water"
        ></textarea>
        <button
          type="button"
          @click="isCheck ? (showCheckModal = true) : complete(true)"
          :disabled="loading || (!isCheck && !notes.trim())"
          class="btn-primary text-sm py-2 px-4 w-full"
        >
          {{ isCheck ? 'Open check form' : 'Save note and mark done' }}
        </button>
      </div>

      <TaskRecommendations :task-id="task.id" :plant-name="plantName" />
    </div>

    <!-- Dialogs -->
    <SkipTaskModal
      :task="task"
      :plant-name="plantName"
      :visible="showSkipModal"
      @close="showSkipModal = false"
      @skipped="handleSkipped"
    />

    <ImageLightbox
      :visible="showImageModal"
      :src="thumbnail ? `/uploads/plants/${thumbnail}` : ''"
      :alt="`Photo of ${plantName}`"
      :caption="plantName"
      @close="showImageModal = false"
    />

    <CareInfoModal
      :plant-id="plant?.id || task.plant_id"
      :plant-name="plantName"
      :visible="showCareInfoModal"
      @close="showCareInfoModal = false"
    />

    <CheckTaskModal
      :task="task"
      :plant-name="plantName"
      :plant-species="plant?.species || task.plant_species"
      :plant-light-condition="plant?.light_condition || task.plant_light_condition"
      :is-water-propagation="(Number(plant?.is_propagation) === 1 || Number(task.plant_is_propagation) === 1) && (plant?.soil_type === 'water' || task.plant_soil_type === 'water')"
      :visible="showCheckModal"
      :saving="savingCheck"
      @close="showCheckModal = false"
      @complete="handleCheckComplete"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'
import { useApi } from '@/composables/useApi'
import LoadingOverlay from '@/components/common/LoadingOverlay.vue'
import CareInfoModal from '@/components/plants/CareInfoModal.vue'
import CheckTaskModal from '@/components/tasks/CheckTaskModal.vue'

const router = useRouter()

const props = defineProps({
  task: { type: Object, required: true },
  plant: { type: Object, default: null },
  completed: { type: Boolean, default: false },
  showRecommendations: { type: Boolean, default: true }
})

const tasks = useTasksStore()
const api = useApi()
const loading = ref(false)
const expanded = ref(false)
const loadingRecs = ref(false)
const recommendations = ref(null)
const recError = ref(null)
const showNotesInput = ref(false)
const notes = ref('')

// Modal states
const showSkipModal = ref(false)
const showImageModal = ref(false)
const showCareInfoModal = ref(false)
const showCheckModal = ref(false)
const skipReason = ref('')
const customSkipReason = ref('')
const skipping = ref(false)
const adjustingSchedule = ref(false)
const scheduleAdjustment = ref(null)

const skipReasons = [
  { value: 'still_moist', label: 'Soil is still moist', icon: '💧' },
  { value: 'plant_away', label: 'Plant is away/outdoors', icon: '🌳' },
  { value: 'recently_done', label: 'Already did this recently', icon: '✅' },
  { value: 'plant_stressed', label: 'Plant seems stressed', icon: '😟' },
  { value: 'weather', label: 'Weather conditions', icon: '🌧️' },
  { value: 'other', label: 'Other reason', icon: '📝' }
]

const emit = defineEmits(['completed', 'skipped'])

const taskIcons = {
  water: { src: 'https://img.icons8.com/doodle/48/watering-can.png', alt: 'water' },
  fertilize: { src: 'https://img.icons8.com/doodle/48/nature-care.png', alt: 'fertilize' },
  trim: { src: 'https://img.icons8.com/doodle/48/cut.png', alt: 'trim' },
  repot: { src: 'https://img.icons8.com/doodle/48/potted-plant.png', alt: 'repot' },
  rotate: { src: 'https://img.icons8.com/doodle/48/rotate.png', alt: 'rotate' },
  mist: { src: 'https://img.icons8.com/doodle/48/splash.png', alt: 'mist' },
  check: { src: 'https://img.icons8.com/doodle/48/visible--v1.png', alt: 'check' },
  change_water: { src: 'https://img.icons8.com/doodle/48/water.png', alt: 'change water' },
  check_roots: { src: 'https://img.icons8.com/doodle/48/soil.png', alt: 'check roots' },
  pot_up: { src: 'https://img.icons8.com/doodle/48/potted-plant.png', alt: 'pot up' }
}

function getTaskIcon(taskType) {
  return taskIcons[taskType] || { src: 'https://img.icons8.com/doodle/48/todo-list.png', alt: 'task' }
}

function goToPlant() {
  if (props.plant?.id || props.task.plant_id) {
    router.push(`/plants/${props.plant?.id || props.task.plant_id}`)
  }
}

async function toggleExpand() {
  expanded.value = !expanded.value

  // Fetch recommendations when first expanding
  if (expanded.value && !recommendations.value && !loadingRecs.value) {
    await fetchRecommendations()
  }
}

async function fetchRecommendations() {
  loadingRecs.value = true
  recError.value = null

  try {
    const response = await api.get(`/tasks/${props.task.id}/recommendations`)
    recommendations.value = response.recommendations
  } catch (e) {
    recError.value = e.message || 'Failed to load recommendations'
    console.error('Failed to fetch recommendations:', e)
  } finally {
    loadingRecs.value = false
  }
}

function handleCheckboxClick() {
  if (props.completed) return
  // For check tasks, show the structured data modal
  if (props.task.task_type === 'check') {
    showCheckModal.value = true
  } else {
    showNotesInput.value = true
  }
}

async function complete(withNotes = false) {
  if (loading.value || props.completed) return
  loading.value = true
  try {
    await tasks.completeTask(props.task.id, withNotes ? notes.value : null)
    window.$toast?.success('Task completed!')
    showNotesInput.value = false
    notes.value = ''
    emit('completed', props.task)
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    loading.value = false
  }
}

function cancelNotes() {
  showNotesInput.value = false
  notes.value = ''
}

async function handleCheckComplete(checkData) {
  if (loading.value || props.completed) return
  loading.value = true
  try {
    await tasks.completeCheckTask(props.task.id, checkData)
    window.$toast?.success('Check completed!')
    showCheckModal.value = false
    emit('completed', props.task)
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    loading.value = false
  }
}

function openSkipModal() {
  showSkipModal.value = true
  skipReason.value = ''
  customSkipReason.value = ''
  scheduleAdjustment.value = null
}

function closeSkipModal() {
  showSkipModal.value = false
  skipReason.value = ''
  customSkipReason.value = ''
  scheduleAdjustment.value = null
}

function getSkipReasonText() {
  if (skipReason.value === 'other') {
    return customSkipReason.value
  }
  const reason = skipReasons.find(r => r.value === skipReason.value)
  return reason ? reason.label : skipReason.value
}

async function skipWithReason() {
  // Skip reason is now optional - can skip without selecting a reason
  if (skipReason.value === 'other' && !customSkipReason.value.trim()) {
    // If "other" is selected, require custom text
    return
  }

  skipping.value = true
  try {
    const reason = skipReason.value ? getSkipReasonText() : null
    await tasks.skipTask(props.task.id, reason)
    window.$toast?.success('Task skipped')
    closeSkipModal()
    emit('skipped', props.task)
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    skipping.value = false
  }
}

async function getAIScheduleAdjustment() {
  if (!skipReason.value) return

  adjustingSchedule.value = true
  try {
    const response = await api.post(`/tasks/${props.task.id}/adjust-schedule`, {
      reason: getSkipReasonText()
    })
    scheduleAdjustment.value = response
  } catch (error) {
    window.$toast?.error(error.message || 'Could not get schedule suggestion')
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
    // Remove task from list since it was adjusted
    await tasks.fetchTodayTasks()
    window.$toast?.success('Schedule updated!')
    closeSkipModal()
    emit('skipped', props.task)
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    skipping.value = false
  }
}
</script>

<template>
  <div class="bg-cream-50 rounded-2xl border-2 border-sage-100" :class="{ 'opacity-60': completed }">
    <!-- Main row: checkbox, thumbnail, task info -->
    <div class="flex items-center gap-3 p-3">
      <!-- Checkbox -->
      <button
        @click="handleCheckboxClick"
        :disabled="loading || completed"
        class="task-checkbox flex-shrink-0"
        :class="{ 'checked': completed }"
      >
        <svg v-if="completed" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <div v-else-if="loading" class="w-4 h-4 border-2 border-sage-500 border-t-transparent rounded-full animate-spin"></div>
      </button>

      <!-- Plant thumbnail (clickable to view larger) -->
      <button
        v-if="plant?.thumbnail || task.plant_thumbnail"
        @click="showImageModal = true"
        class="w-12 h-12 rounded-xl overflow-hidden flex-shrink-0 border-2 border-sage-200 hover:border-sage-400 transition-colors cursor-pointer"
      >
        <img :src="`/uploads/plants/${plant?.thumbnail || task.plant_thumbnail}`" :alt="plant?.name || task.plant_name" class="w-full h-full object-cover">
      </button>
      <div v-else class="w-12 h-12 rounded-xl bg-cream-200 flex items-center justify-center flex-shrink-0">
        <img :src="getTaskIcon(task.task_type).src" :alt="getTaskIcon(task.task_type).alt" class="w-6 h-6">
      </div>

      <!-- Task info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
          <img :src="getTaskIcon(task.task_type).src" :alt="getTaskIcon(task.task_type).alt" class="w-5 h-5">
          <span class="font-semibold text-charcoal-600 capitalize text-sm">{{ task.task_type.replace('_', ' ') }}</span>
          <span
            v-if="task.priority === 'high' || task.priority === 'urgent'"
            class="px-1.5 py-0.5 text-xs font-medium rounded-full"
            :class="task.priority === 'urgent' ? 'bg-terracotta-100 text-terracotta-700' : 'bg-sunny-100 text-sunny-700'"
          >
            {{ task.priority }}
          </span>
        </div>
        <div class="flex items-center gap-1">
          <button
            @click="goToPlant"
            class="text-sm text-charcoal-400 truncate hover:text-sage-600 hover:underline transition-colors text-left"
          >
            {{ plant?.name || task.plant_name }}
          </button>
          <button
            @click.stop="showCareInfoModal = true"
            class="p-1 text-charcoal-300 hover:text-plant-600 transition-colors flex-shrink-0"
            title="View care guide"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </button>
        </div>
        <p v-if="task.completed_by_name" class="text-xs text-charcoal-400">
          Completed by {{ task.completed_by_name }}
        </p>
      </div>

      <!-- Details button -->
      <button
        v-if="showRecommendations && !completed"
        @click="toggleExpand"
        class="p-2 text-charcoal-400 hover:text-sage-600 transition-colors flex-shrink-0"
        :class="{ 'text-sage-600': expanded }"
      >
        <svg class="w-5 h-5 transition-transform" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </button>
    </div>

    <!-- Notes input for completing task -->
    <div v-if="showNotesInput" class="px-3 pb-3">
      <div class="bg-sage-50 rounded-xl p-3 border border-sage-200">
        <p class="text-sm text-charcoal-600 mb-2">Add a note (optional)</p>
        <textarea
          v-model="notes"
          rows="2"
          class="input text-sm resize-none mb-2"
          placeholder="e.g., Used 1 cup of water..."
        ></textarea>
        <div class="flex gap-2">
          <button
            @click="cancelNotes"
            class="btn-secondary text-xs py-1.5 px-3 flex-1"
          >
            Cancel
          </button>
          <button
            @click="complete(true)"
            :disabled="loading"
            class="btn-primary text-xs py-1.5 px-3 flex-1"
          >
            <span v-if="loading" class="flex items-center justify-center gap-1">
              <div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
            </span>
            <span v-else>Complete</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Expanded details section -->
    <div v-if="expanded && !showNotesInput" class="px-3 pb-3">
      <!-- Action buttons -->
      <div class="flex items-center gap-3 mb-3 pb-3 border-b border-cream-200">
        <button
          @click="openSkipModal"
          class="text-xs text-charcoal-400 hover:text-terracotta-500 font-semibold flex items-center gap-1 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
          </svg>
          Skip task
        </button>
      </div>

      <!-- Loading state -->
      <div v-if="loadingRecs" class="bg-plant-50 rounded-xl p-4 border border-plant-100">
        <div class="flex items-center gap-3">
          <div class="w-5 h-5 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
          <span class="text-sm text-plant-700">Getting personalized care tips...</span>
        </div>
      </div>

      <!-- Error state -->
      <div v-else-if="recError" class="bg-red-50 rounded-xl p-4 border border-red-100">
        <p class="text-sm text-red-700">{{ recError }}</p>
        <button @click="fetchRecommendations" class="mt-2 text-xs text-red-600 underline">Try again</button>
      </div>

      <!-- Recommendations -->
      <div v-else-if="recommendations" class="bg-plant-50 rounded-xl p-4 border border-plant-100 space-y-3">
        <!-- AI badge -->
        <div class="flex items-center gap-2 text-xs text-plant-600">
          <svg v-if="recommendations.source === 'ai'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
          </svg>
          <span>{{ recommendations.source === 'ai' ? 'AI-personalized for ' + (plant?.name || task.plant_name) : 'Care tips' }}</span>
        </div>

        <!-- Summary -->
        <p class="font-medium text-gray-800">{{ recommendations.summary }}</p>

        <!-- Amount/Timing -->
        <div v-if="recommendations.amount || recommendations.timing" class="flex flex-wrap gap-2 text-xs">
          <span v-if="recommendations.amount" class="bg-white px-2 py-1 rounded-lg border border-plant-200">
            {{ recommendations.amount }}
          </span>
          <span v-if="recommendations.timing" class="bg-white px-2 py-1 rounded-lg border border-plant-200">
            {{ recommendations.timing }}
          </span>
        </div>

        <!-- Steps -->
        <div v-if="recommendations.steps?.length" class="space-y-2">
          <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Steps</p>
          <ol class="space-y-1.5 text-sm text-gray-700">
            <li v-for="(step, index) in recommendations.steps" :key="index" class="flex gap-2">
              <span class="flex-shrink-0 w-5 h-5 bg-plant-200 text-plant-800 rounded-full text-xs flex items-center justify-center font-medium">{{ index + 1 }}</span>
              <span>{{ step }}</span>
            </li>
          </ol>
        </div>

        <!-- Warnings -->
        <div v-if="recommendations.warnings?.length" class="bg-yellow-50 rounded-lg p-3 border border-yellow-200">
          <p class="text-xs font-medium text-yellow-800 mb-1">Watch out</p>
          <ul class="space-y-1 text-sm text-yellow-700">
            <li v-for="(warning, index) in recommendations.warnings" :key="index">{{ warning }}</li>
          </ul>
        </div>

        <!-- Tips -->
        <div v-if="recommendations.tips?.length" class="text-sm text-gray-600">
          <p class="text-xs font-medium text-gray-500 mb-1">Tips</p>
          <ul class="space-y-1">
            <li v-for="(tip, index) in recommendations.tips" :key="index">{{ tip }}</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Loading overlay for AI recommendations -->
    <LoadingOverlay :visible="loadingRecs" :message="`Getting tips for ${plant?.name || task.plant_name}...`" />

    <!-- Skip Modal -->
    <Teleport to="body">
      <div v-if="showSkipModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" @click="closeSkipModal"></div>

        <!-- Modal content -->
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[85vh] overflow-y-auto">
          <div class="p-4 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold text-gray-900">Skip Task</h3>
              <button @click="closeSkipModal" class="p-1 text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <p class="text-sm text-gray-500 mt-1 flex items-center gap-1">
              <img :src="getTaskIcon(task.task_type).src" :alt="getTaskIcon(task.task_type).alt" class="w-4 h-4 inline">
              <span class="capitalize">{{ task.task_type.replace('_', ' ') }}</span> - {{ plant?.name || task.plant_name }}
            </p>
          </div>

          <div class="p-4 space-y-4">
            <!-- Reason selection -->
            <div>
              <p class="text-sm font-medium text-gray-700 mb-2">Why are you skipping?</p>
              <div class="space-y-2">
                <label
                  v-for="reason in skipReasons"
                  :key="reason.value"
                  class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all"
                  :class="skipReason === reason.value
                    ? 'border-plant-500 bg-plant-50'
                    : 'border-gray-200 hover:border-gray-300'"
                >
                  <input
                    type="radio"
                    v-model="skipReason"
                    :value="reason.value"
                    class="sr-only"
                  >
                  <span class="text-lg">{{ reason.icon }}</span>
                  <span class="text-sm text-gray-700">{{ reason.label }}</span>
                </label>
              </div>
            </div>

            <!-- Custom reason input -->
            <div v-if="skipReason === 'other'">
              <textarea
                v-model="customSkipReason"
                rows="2"
                class="input text-sm"
                placeholder="Describe why you're skipping..."
              ></textarea>
            </div>

            <!-- AI Schedule Adjustment -->
            <div v-if="skipReason && !scheduleAdjustment" class="pt-2 border-t">
              <button
                @click="getAIScheduleAdjustment"
                :disabled="adjustingSchedule || (skipReason === 'other' && !customSkipReason.trim())"
                class="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl border-2 border-dashed border-plant-300 text-plant-600 hover:bg-plant-50 transition-colors disabled:opacity-50"
              >
                <svg v-if="adjustingSchedule" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                </svg>
                <span class="text-sm font-medium">
                  {{ adjustingSchedule ? 'Analyzing...' : 'Ask AI to adjust schedule' }}
                </span>
              </button>
              <p class="text-xs text-gray-500 text-center mt-2">
                AI will suggest a new schedule based on your feedback
              </p>
            </div>

            <!-- AI Suggestion -->
            <div v-if="scheduleAdjustment" class="bg-plant-50 rounded-xl p-4 border border-plant-200">
              <div class="flex items-start gap-3">
                <div class="w-8 h-8 bg-plant-200 rounded-full flex items-center justify-center flex-shrink-0">
                  <svg class="w-4 h-4 text-plant-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                  </svg>
                </div>
                <div class="flex-1">
                  <p class="font-medium text-plant-800 text-sm">AI Suggestion</p>
                  <p class="text-sm text-plant-700 mt-1">{{ scheduleAdjustment.suggestion }}</p>
                  <p v-if="scheduleAdjustment.new_interval" class="text-xs text-plant-600 mt-2">
                    New schedule: Every {{ scheduleAdjustment.new_interval }} days
                  </p>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-2">
              <button
                @click="skipWithReason"
                :disabled="skipping || (skipReason === 'other' && !customSkipReason.trim())"
                class="flex-1 btn-secondary"
              >
                <span v-if="skipping" class="flex items-center justify-center gap-2">
                  <div class="w-4 h-4 border-2 border-gray-400 border-t-transparent rounded-full animate-spin"></div>
                </span>
                <span v-else>Skip{{ skipReason ? '' : ' Without Reason' }}</span>
              </button>
              <button
                v-if="scheduleAdjustment"
                @click="applyScheduleAdjustment"
                :disabled="skipping"
                class="flex-1 btn-primary"
              >
                <span v-if="skipping" class="flex items-center justify-center gap-2">
                  <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                </span>
                <span v-else>Skip & Update Schedule</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Image lightbox modal -->
    <Teleport to="body">
      <div
        v-if="showImageModal"
        class="fixed inset-0 bg-black/80 flex items-center justify-center z-50 p-4"
        @click.self="showImageModal = false"
      >
        <div class="relative max-w-lg w-full">
          <button
            @click="showImageModal = false"
            class="absolute -top-12 right-0 text-white/80 hover:text-white p-2"
          >
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <img
            :src="`/uploads/plants/${plant?.thumbnail || task.plant_thumbnail}`"
            :alt="plant?.name || task.plant_name"
            class="w-full rounded-2xl shadow-2xl"
          >
          <p class="text-white text-center mt-3 font-medium">{{ plant?.name || task.plant_name }}</p>
        </div>
      </div>
    </Teleport>

    <!-- Care Info Modal -->
    <CareInfoModal
      :plant-id="plant?.id || task.plant_id"
      :plant-name="plant?.name || task.plant_name"
      :visible="showCareInfoModal"
      @close="showCareInfoModal = false"
    />

    <!-- Check Task Modal -->
    <CheckTaskModal
      :task="task"
      :plant-name="plant?.name || task.plant_name"
      :visible="showCheckModal"
      @close="showCheckModal = false"
      @complete="handleCheckComplete"
    />
  </div>
</template>

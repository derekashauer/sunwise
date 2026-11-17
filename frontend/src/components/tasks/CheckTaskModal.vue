<script setup>
import { ref, computed, watch } from 'vue'
import BaseModal from '@/components/common/BaseModal.vue'

const props = defineProps({
  task: { type: Object, required: true },
  plantName: { type: String, default: '' },
  plantSpecies: { type: String, default: '' },
  plantLightCondition: { type: String, default: '' },
  isWaterPropagation: { type: Boolean, default: false },
  visible: { type: Boolean, default: false },
  // True while the parent is saving the check. The form stays open until the
  // save succeeds so nothing is lost if it fails.
  saving: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'complete'])

// Form state
const moistureLevel = ref(5)
const newGrowth = ref(false)
const yellowingLeaves = ref(false)
const brownTips = ref(false)
const pestsObserved = ref(false)
const dustyDirty = ref(false)
const pestNotes = ref('')
const generalHealth = ref(3)
const notes = ref('')
const confirmDiscard = ref(false)

// Reset form when modal opens
watch(() => props.visible, (visible) => {
  if (visible) {
    moistureLevel.value = 5
    newGrowth.value = false
    yellowingLeaves.value = false
    brownTips.value = false
    pestsObserved.value = false
    dustyDirty.value = false
    pestNotes.value = ''
    generalHealth.value = 3
    notes.value = ''
    confirmDiscard.value = false
  }
})

// Anything changed from the starting values counts as entered data.
const isDirty = computed(() =>
  moistureLevel.value !== 5 ||
  newGrowth.value ||
  yellowingLeaves.value ||
  brownTips.value ||
  pestsObserved.value ||
  dustyDirty.value ||
  !!pestNotes.value.trim() ||
  generalHealth.value !== 3 ||
  !!notes.value.trim()
)

const currentTime = computed(() => {
  const now = new Date()
  return now.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true
  })
})

const moistureLabel = computed(() => {
  if (moistureLevel.value <= 3) return 'Dry'
  if (moistureLevel.value <= 7) return 'Moist'
  return 'Wet'
})

const healthLabels = ['danger', 'poor', 'okay', 'good', 'great']
const healthEmojis = ['😟', '😕', '😐', '😊', '🌟']

// Closing with data entered asks first, so a stray tap doesn't lose a check.
function requestClose() {
  if (props.saving) return
  if (isDirty.value && !confirmDiscard.value) {
    confirmDiscard.value = true
    return
  }
  confirmDiscard.value = false
  emit('close')
}

function keepEditing() {
  confirmDiscard.value = false
}

function complete() {
  if (props.saving) return

  const checkData = {
    moisture_level: props.isWaterPropagation ? null : moistureLevel.value,
    light_reading: null,
    recorded_at: new Date().toISOString(),
    new_growth: newGrowth.value,
    yellowing_leaves: yellowingLeaves.value,
    brown_tips: brownTips.value,
    pests_observed: pestsObserved.value,
    dusty_dirty: dustyDirty.value,
    pest_notes: pestsObserved.value ? pestNotes.value : null,
    general_health: generalHealth.value,
    notes: notes.value || null
  }

  emit('complete', checkData)
}

const observationButtons = [
  { key: 'newGrowth', label: 'New growth', emoji: '🌱', on: 'border-plant-600 bg-plant-50 text-plant-800' },
  { key: 'yellowingLeaves', label: 'Yellowing', emoji: '🍂', on: 'border-sunny-700 bg-sunny-50 text-sunny-900' },
  { key: 'pestsObserved', label: 'Pests', emoji: '🐛', on: 'border-red-600 bg-red-50 text-red-800' },
  { key: 'brownTips', label: 'Brown tips', emoji: '🍃', on: 'border-amber-600 bg-amber-50 text-amber-900' },
  { key: 'dustyDirty', label: 'Dusty', emoji: '💨', on: 'border-charcoal-400 bg-charcoal-50 text-charcoal-700' }
]

const observationRefs = { newGrowth, yellowingLeaves, pestsObserved, brownTips, dustyDirty }

function toggleObservation(key) {
  observationRefs[key].value = !observationRefs[key].value
}
</script>

<template>
  <BaseModal
    :visible="visible"
    title="Check plant"
    :subtitle="plantName"
    close-label="Close check form"
    body-class="p-4 space-y-5"
    @close="requestClose"
  >
    <!-- Discard confirmation -->
    <div v-if="confirmDiscard" class="rounded-xl border-2 border-terracotta-300 bg-terracotta-50 p-4" role="alert">
      <p class="font-semibold text-charcoal-700">Discard this check?</p>
      <p class="text-sm text-charcoal-600 mt-1">What you entered for {{ plantName }} won't be saved.</p>
      <div class="flex gap-2 mt-3">
        <button type="button" @click="keepEditing" class="btn-secondary flex-1 py-2" autofocus>Keep editing</button>
        <button type="button" @click="requestClose" class="btn flex-1 py-2 bg-red-700 text-white hover:bg-red-800">Discard</button>
      </div>
    </div>

    <p class="text-center text-sm text-charcoal-500">Recording at {{ currentTime }}</p>

    <!-- Moisture level (hidden for water propagations) -->
    <div v-if="!isWaterPropagation">
      <label for="check-moisture" class="block text-sm font-medium text-charcoal-600 mb-2">
        Soil moisture
      </label>
      <div class="space-y-2">
        <input
          id="check-moisture"
          type="range"
          v-model.number="moistureLevel"
          min="1"
          max="10"
          class="w-full h-2 rounded-lg appearance-none cursor-pointer"
          :aria-valuetext="`${moistureLevel} out of 10, ${moistureLabel}`"
        >
        <div class="flex justify-between items-center">
          <div class="flex gap-2 text-xs">
            <span class="text-terracotta-700">Dry (1-3)</span>
            <span class="text-plant-700">Moist (4-7)</span>
            <span class="text-sky-700">Wet (8-10)</span>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-lg font-semibold" :class="{
              'text-terracotta-700': moistureLevel <= 3,
              'text-plant-700': moistureLevel > 3 && moistureLevel <= 7,
              'text-sky-700': moistureLevel > 7
            }">{{ moistureLevel }}</span>
            <span class="text-sm text-charcoal-500">({{ moistureLabel }})</span>
          </div>
        </div>
      </div>
    </div>
    <div v-else class="bg-sky-50 rounded-xl p-3 border border-sky-100">
      <p class="text-sm text-sky-800">Water propagation, so there's no soil moisture to measure.</p>
    </div>

    <!-- Observations -->
    <fieldset>
      <legend class="block text-sm font-medium text-charcoal-600 mb-2">What do you see?</legend>
      <div class="grid grid-cols-3 gap-2">
        <button
          v-for="item in observationButtons"
          :key="item.key"
          type="button"
          @click="toggleObservation(item.key)"
          :aria-pressed="observationRefs[item.key].value"
          class="flex flex-col items-center gap-1 p-3 rounded-xl border-2 transition-all"
          :class="observationRefs[item.key].value ? item.on : 'border-gray-200 text-charcoal-500 hover:border-gray-300'"
        >
          <span class="text-xl" aria-hidden="true">{{ item.emoji }}</span>
          <span class="text-xs font-medium">{{ item.label }}</span>
        </button>
      </div>
    </fieldset>

    <!-- Pest notes (if pests observed) -->
    <div v-if="pestsObserved">
      <label for="check-pest-notes" class="block text-sm font-medium text-charcoal-600 mb-2">
        What did you see?
      </label>
      <input
        id="check-pest-notes"
        v-model="pestNotes"
        type="text"
        placeholder="e.g., spider mites, fungus gnats..."
        class="input"
      >
    </div>

    <!-- Overall health -->
    <fieldset>
      <legend class="block text-sm font-medium text-charcoal-600 mb-2">Overall health</legend>
      <div class="flex justify-between gap-2">
        <button
          v-for="(label, index) in healthLabels"
          :key="label"
          type="button"
          @click="generalHealth = index + 1"
          :aria-pressed="generalHealth === index + 1"
          class="flex-1 flex flex-col items-center gap-1 p-2 rounded-xl border-2 transition-all"
          :class="generalHealth === index + 1
            ? 'border-plant-600 bg-plant-50'
            : 'border-gray-200 hover:border-gray-300'"
        >
          <span class="text-2xl" aria-hidden="true">{{ healthEmojis[index] }}</span>
          <span class="text-xs capitalize" :class="generalHealth === index + 1 ? 'text-plant-800 font-medium' : 'text-charcoal-500'">{{ label }}</span>
        </button>
      </div>
    </fieldset>

    <!-- Notes -->
    <div>
      <label for="check-notes" class="block text-sm font-medium text-charcoal-600 mb-2">
        Notes (optional)
      </label>
      <textarea
        id="check-notes"
        v-model="notes"
        rows="2"
        class="input resize-none"
        placeholder="Any other observations..."
      ></textarea>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          type="button"
          @click="requestClose"
          :disabled="saving"
          class="flex-1 btn-secondary"
        >
          Cancel
        </button>
        <button
          type="button"
          @click="complete"
          :disabled="saving"
          class="flex-1 btn-primary flex items-center justify-center gap-2"
        >
          <span v-if="saving" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
          <span>{{ saving ? 'Saving...' : 'Save check' }}</span>
        </button>
      </div>
    </template>
  </BaseModal>
</template>

<style scoped>
input[type="range"] {
  -webkit-appearance: none;
  appearance: none;
  background: linear-gradient(to right, #b45309, #b45309 30%, #3F7A41 30%, #3F7A41 70%, #0369a1 70%, #0369a1);
  border-radius: 9999px;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 28px;
  height: 28px;
  background: white;
  border: 2px solid #4b5563;
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

input[type="range"]::-moz-range-thumb {
  width: 28px;
  height: 28px;
  background: white;
  border: 2px solid #4b5563;
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

input[type="range"]:focus-visible {
  outline: 2px solid #3F7A41;
  outline-offset: 4px;
}
</style>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  task: { type: Object, required: true },
  plantName: { type: String, default: '' },
  plantSpecies: { type: String, default: '' },
  plantLightCondition: { type: String, default: '' },
  isWaterPropagation: { type: Boolean, default: false },
  visible: { type: Boolean, default: false },
  insights: { type: Array, default: () => [] }
})

const emit = defineEmits(['close', 'complete'])

// Form state
const moistureLevel = ref(5)
const lightReading = ref('')
const newGrowth = ref(false)
const yellowingLeaves = ref(false)
const brownTips = ref(false)
const pestsObserved = ref(false)
const dustyDirty = ref(false)
const pestNotes = ref('')
const generalHealth = ref(3)
const notes = ref('')
const submitting = ref(false)

// Reset form when modal opens
watch(() => props.visible, (visible) => {
  if (visible) {
    moistureLevel.value = 5
    lightReading.value = ''
    newGrowth.value = false
    yellowingLeaves.value = false
    brownTips.value = false
    pestsObserved.value = false
    dustyDirty.value = false
    pestNotes.value = ''
    generalHealth.value = 3
    notes.value = ''
  }
})

const currentTime = computed(() => {
  const now = new Date()
  return now.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
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

const moistureColor = computed(() => {
  if (moistureLevel.value <= 3) return 'bg-terracotta-500'
  if (moistureLevel.value <= 7) return 'bg-plant-500'
  return 'bg-sky-500'
})

const healthEmojis = ['danger', 'poor', 'okay', 'good', 'great']

const showingInsights = computed(() => props.insights && props.insights.length > 0)

// Light level ranges in foot-candles for different plant types
const lightRanges = {
  low: {
    label: 'Low Light',
    ideal: '50-250',
    ranges: [
      { label: 'Too low', range: '< 50', color: 'text-amber-600' },
      { label: 'Ideal', range: '50-250', color: 'text-plant-600' },
      { label: 'Acceptable', range: '250-500', color: 'text-sky-600' },
      { label: 'Too bright', range: '> 500', color: 'text-red-500' }
    ]
  },
  medium: {
    label: 'Medium Light',
    ideal: '250-1000',
    ranges: [
      { label: 'Too low', range: '< 100', color: 'text-amber-600' },
      { label: 'Low', range: '100-250', color: 'text-sky-600' },
      { label: 'Ideal', range: '250-1000', color: 'text-plant-600' },
      { label: 'High (ok)', range: '1000-2000', color: 'text-sky-600' }
    ]
  },
  high: {
    label: 'High Light',
    ideal: '1000-2000+',
    ranges: [
      { label: 'Too low', range: '< 500', color: 'text-amber-600' },
      { label: 'Low', range: '500-1000', color: 'text-sky-600' },
      { label: 'Ideal', range: '1000-2000', color: 'text-plant-600' },
      { label: 'Bright', range: '> 2000', color: 'text-sunny-600' }
    ]
  },
  'full sun': {
    label: 'Full Sun',
    ideal: '2000-5000+',
    ranges: [
      { label: 'Too low', range: '< 1000', color: 'text-amber-600' },
      { label: 'Moderate', range: '1000-2000', color: 'text-sky-600' },
      { label: 'Good', range: '2000-5000', color: 'text-plant-600' },
      { label: 'Direct sun', range: '> 5000', color: 'text-sunny-600' }
    ]
  }
}

// General ranges when no plant-specific data
const generalLightRanges = [
  { label: 'Low', range: '50-250 fc', description: 'Shade-tolerant plants' },
  { label: 'Medium', range: '250-1000 fc', description: 'Most houseplants' },
  { label: 'High', range: '1000-2000 fc', description: 'Bright indirect' },
  { label: 'Direct', range: '2000+ fc', description: 'Full sun plants' }
]

const plantLightInfo = computed(() => {
  const condition = props.plantLightCondition?.toLowerCase()
  if (condition && lightRanges[condition]) {
    return lightRanges[condition]
  }
  return null
})

function getInsightStyle(type) {
  switch (type) {
    case 'urgent':
      return 'bg-red-50 border-red-200 text-red-800'
    case 'warning':
      return 'bg-amber-50 border-amber-200 text-amber-800'
    case 'success':
      return 'bg-plant-50 border-plant-200 text-plant-800'
    default:
      return 'bg-sky-50 border-sky-200 text-sky-800'
  }
}

function getInsightIcon(type) {
  switch (type) {
    case 'urgent':
      return '&#128680;' // 🚨
    case 'warning':
      return '&#9888;' // ⚠️
    case 'success':
      return '&#127793;' // 🌱
    default:
      return '&#128161;' // 💡
  }
}

function close() {
  emit('close')
}

async function complete() {
  if (submitting.value) return
  submitting.value = true

  const checkData = {
    moisture_level: props.isWaterPropagation ? null : moistureLevel.value,
    light_reading: lightReading.value ? parseInt(lightReading.value) : null,
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
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
    >
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/50" @click="close"></div>

      <!-- Modal -->
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[90vh] overflow-hidden flex flex-col">
        <!-- Header -->
        <div class="sticky top-0 bg-white border-b px-4 py-3 flex items-center justify-between z-10">
          <div>
            <h2 class="font-semibold text-gray-900 flex items-center gap-2">
              <img src="https://img.icons8.com/doodle/48/visible--v1.png" alt="check" class="w-5 h-5">
              Check Plant
            </h2>
            <p class="text-sm text-gray-500">{{ plantName }}</p>
          </div>
          <button @click="close" class="p-2 text-gray-400 hover:text-gray-600 -mr-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-4 space-y-5">
          <!-- Insights view (shown after completion) -->
          <template v-if="showingInsights">
            <div class="text-center py-4">
              <div class="w-16 h-16 bg-plant-100 rounded-full flex items-center justify-center mx-auto mb-3">
                <span class="text-3xl">&#9989;</span>
              </div>
              <h3 class="text-lg font-semibold text-gray-900">Check Complete!</h3>
              <p class="text-sm text-gray-500 mt-1">{{ plantName }}</p>
            </div>

            <div class="space-y-3">
              <p class="text-sm font-medium text-gray-700">Insights from your check:</p>
              <div
                v-for="(insight, index) in insights"
                :key="index"
                class="rounded-xl p-4 border"
                :class="getInsightStyle(insight.type)"
              >
                <div class="flex items-start gap-3">
                  <span class="text-xl flex-shrink-0" v-html="getInsightIcon(insight.type)"></span>
                  <div class="flex-1">
                    <p class="font-medium text-sm">{{ insight.message }}</p>
                    <p v-if="insight.suggestion?.details" class="text-xs mt-1 opacity-80">
                      {{ insight.suggestion.details }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <!-- Form view (shown before completion) -->
          <template v-else>
          <!-- Time display -->
          <div class="text-center text-sm text-gray-500">
            Recording at: {{ currentTime }}
          </div>

          <!-- Moisture Level (hide for water propagations) -->
          <div v-if="!isWaterPropagation">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Moisture Level
            </label>
            <div class="space-y-2">
              <input
                type="range"
                v-model.number="moistureLevel"
                min="1"
                max="10"
                class="w-full h-2 rounded-lg appearance-none cursor-pointer"
                :class="moistureColor"
              >
              <div class="flex justify-between items-center">
                <div class="flex gap-2 text-xs text-gray-500">
                  <span class="text-terracotta-600">Dry (1-3)</span>
                  <span class="text-plant-600">Moist (4-7)</span>
                  <span class="text-sky-600">Wet (8-10)</span>
                </div>
                <div class="flex items-center gap-2">
                  <span class="text-lg font-semibold" :class="{
                    'text-terracotta-600': moistureLevel <= 3,
                    'text-plant-600': moistureLevel > 3 && moistureLevel <= 7,
                    'text-sky-600': moistureLevel > 7
                  }">{{ moistureLevel }}</span>
                  <span class="text-sm text-gray-500">({{ moistureLabel }})</span>
                </div>
              </div>
            </div>
          </div>
          <!-- Water propagation notice -->
          <div v-else class="bg-sky-50 rounded-xl p-3 border border-sky-100">
            <p class="text-sm text-sky-700">Water propagation - no soil moisture to measure</p>
          </div>

          <!-- Light Reading (hidden - now set on plant add/edit instead) -->
          <input type="hidden" v-model="lightReading" />

          <!-- Observations -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Observations
            </label>
            <div class="grid grid-cols-3 gap-2">
              <button
                type="button"
                @click="newGrowth = !newGrowth"
                class="flex flex-col items-center gap-1 p-3 rounded-xl border-2 transition-all"
                :class="newGrowth ? 'border-plant-500 bg-plant-50 text-plant-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'"
              >
                <span class="text-xl">&#127793;</span>
                <span class="text-xs font-medium">New Growth</span>
              </button>

              <button
                type="button"
                @click="yellowingLeaves = !yellowingLeaves"
                class="flex flex-col items-center gap-1 p-3 rounded-xl border-2 transition-all"
                :class="yellowingLeaves ? 'border-sunny-500 bg-sunny-50 text-sunny-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'"
              >
                <span class="text-xl">&#127810;</span>
                <span class="text-xs font-medium">Yellowing</span>
              </button>

              <button
                type="button"
                @click="pestsObserved = !pestsObserved"
                class="flex flex-col items-center gap-1 p-3 rounded-xl border-2 transition-all"
                :class="pestsObserved ? 'border-red-500 bg-red-50 text-red-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'"
              >
                <span class="text-xl">&#128027;</span>
                <span class="text-xs font-medium">Pests</span>
              </button>

              <button
                type="button"
                @click="brownTips = !brownTips"
                class="flex flex-col items-center gap-1 p-3 rounded-xl border-2 transition-all"
                :class="brownTips ? 'border-amber-500 bg-amber-50 text-amber-700' : 'border-gray-200 text-gray-500 hover:border-gray-300'"
              >
                <span class="text-xl">&#127811;</span>
                <span class="text-xs font-medium">Brown Tips</span>
              </button>

              <button
                type="button"
                @click="dustyDirty = !dustyDirty"
                class="flex flex-col items-center gap-1 p-3 rounded-xl border-2 transition-all"
                :class="dustyDirty ? 'border-charcoal-400 bg-charcoal-50 text-charcoal-600' : 'border-gray-200 text-gray-500 hover:border-gray-300'"
              >
                <span class="text-xl">&#128168;</span>
                <span class="text-xs font-medium">Dusty</span>
              </button>
            </div>
          </div>

          <!-- Pest notes (if pests observed) -->
          <div v-if="pestsObserved">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              What did you see?
            </label>
            <input
              v-model="pestNotes"
              type="text"
              placeholder="e.g., spider mites, fungus gnats..."
              class="input"
            >
          </div>

          <!-- Overall Health -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Overall Health
            </label>
            <div class="flex justify-between gap-2">
              <button
                v-for="(label, index) in healthEmojis"
                :key="index"
                type="button"
                @click="generalHealth = index + 1"
                class="flex-1 flex flex-col items-center gap-1 p-2 rounded-xl border-2 transition-all"
                :class="generalHealth === index + 1
                  ? 'border-plant-500 bg-plant-50'
                  : 'border-gray-200 hover:border-gray-300'"
              >
                <span class="text-2xl">{{ ['&#128543;', '&#128533;', '&#128528;', '&#128522;', '&#127775;'][index] }}</span>
                <span class="text-xs capitalize" :class="generalHealth === index + 1 ? 'text-plant-700 font-medium' : 'text-gray-400'">{{ label }}</span>
              </button>
            </div>
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Notes (optional)
            </label>
            <textarea
              v-model="notes"
              rows="2"
              class="input resize-none"
              placeholder="Any other observations..."
            ></textarea>
          </div>
          </template>
        </div>

        <!-- Footer -->
        <div class="sticky bottom-0 bg-white border-t p-4 flex gap-3">
          <template v-if="showingInsights">
            <button
              @click="close"
              class="flex-1 btn-primary"
            >
              Done
            </button>
          </template>
          <template v-else>
            <button
              @click="close"
              :disabled="submitting"
              class="flex-1 btn-secondary"
            >
              Cancel
            </button>
            <button
              @click="complete"
              :disabled="submitting"
              class="flex-1 btn-primary flex items-center justify-center gap-2"
            >
              <span v-if="submitting" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
              <span v-else>Complete Check</span>
            </button>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
input[type="range"] {
  -webkit-appearance: none;
  appearance: none;
  background: linear-gradient(to right, #d97706, #d97706 30%, #22c55e 30%, #22c55e 70%, #0ea5e9 70%, #0ea5e9);
  border-radius: 9999px;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 20px;
  height: 20px;
  background: white;
  border: 2px solid #6b7280;
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

input[type="range"]::-moz-range-thumb {
  width: 20px;
  height: 20px;
  background: white;
  border: 2px solid #6b7280;
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
</style>

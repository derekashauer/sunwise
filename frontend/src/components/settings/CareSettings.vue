<script setup>
// Plant care settings: time zone, climate notes, how you fertilize, and the
// winter fertilizer break. These shape every care plan Sunwise builds.
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { deviceTimeZone } from '@/utils/dates'

const api = useApi()

const loading = ref(true)
const loadError = ref(null)
const saving = ref(null)
const settings = ref({
  timezone: '',
  climate_notes: '',
  fertilizer_method: 'separate',
  winter_rest: true
})
const climateDraft = ref('')
const timezoneDraft = ref('')

const deviceTz = deviceTimeZone()

const COMMON_ZONES = [
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Phoenix',
  'America/Los_Angeles',
  'America/Anchorage',
  'Pacific/Honolulu',
  'America/Toronto',
  'America/Vancouver',
  'Europe/London',
  'Europe/Berlin',
  'Australia/Sydney'
]

const timeZones = computed(() => {
  let all = []
  try {
    all = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []
  } catch (e) {
    all = []
  }
  const list = all.length ? all : COMMON_ZONES
  const extra = [deviceTz, settings.value.timezone].filter(tz => tz && !list.includes(tz))
  return [...extra, ...list]
})

const timezoneMismatch = computed(() =>
  !loading.value && deviceTz && settings.value.timezone && settings.value.timezone !== deviceTz
)

const climateDirty = computed(() => climateDraft.value.trim() !== (settings.value.climate_notes || '').trim())

function applySettings(data) {
  settings.value = {
    timezone: data.timezone || '',
    climate_notes: data.climate_notes || '',
    fertilizer_method: data.fertilizer_method || 'separate',
    winter_rest: data.winter_rest !== undefined ? !!data.winter_rest : true
  }
  climateDraft.value = settings.value.climate_notes
  timezoneDraft.value = settings.value.timezone || deviceTz
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    applySettings(await api.get('/settings/care'))
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

async function save(field, changes, message) {
  saving.value = field
  try {
    applySettings(await api.put('/settings/care', changes))
    window.$toast?.success(message)
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't save that setting.")
    // Put the controls back to what the server has.
    timezoneDraft.value = settings.value.timezone || deviceTz
  } finally {
    saving.value = null
  }
}

function saveTimezone(tz) {
  if (!tz || tz === settings.value.timezone) return
  save('timezone', { timezone: tz }, `Time zone set to ${tz.replace(/_/g, ' ')}`)
}

function useDeviceTimezone() {
  timezoneDraft.value = deviceTz
  saveTimezone(deviceTz)
}

function saveClimate() {
  save('climate', { climate_notes: climateDraft.value.trim() }, 'Climate notes saved. New care plans will use them.')
}

function setFertilizerMethod(method) {
  if (method === settings.value.fertilizer_method) return
  const label = method === 'separate' ? 'slow-release granules' : 'liquid mixed into water'
  save('fertilizer', { fertilizer_method: method }, `Fertilizer set to ${label}`)
}

function toggleWinterRest() {
  const next = !settings.value.winter_rest
  save('winter', { winter_rest: next }, next ? 'Fertilizing pauses September to February' : 'Fertilizing continues all year')
}

onMounted(load)
</script>

<template>
  <section class="card p-4 mb-6" aria-labelledby="care-settings-title">
    <h2 id="care-settings-title" class="font-hand text-xl text-charcoal-700 mb-1 flex items-center gap-2">
      <img src="https://img.icons8.com/doodle/48/potted-plant--v1.png" alt="" class="w-6 h-6">
      Plant care
    </h2>
    <p class="text-sm text-charcoal-500 mb-4">These shape every care plan Sunwise builds for your plants.</p>

    <div v-if="loading" class="flex justify-center py-4" role="status">
      <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="" class="w-8 h-8 loading-watering-can">
      <span class="sr-only">Loading plant care settings</span>
    </div>

    <div v-else-if="loadError" class="text-sm text-red-800 bg-red-50 rounded-xl p-3" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" @click="load" class="font-semibold underline mt-1 py-1">Try again</button>
    </div>

    <div v-else class="space-y-6">
      <!-- Time zone -->
      <div>
        <label for="care-timezone" class="block text-sm font-semibold text-charcoal-600 mb-1">Time zone</label>
        <p class="text-xs text-charcoal-500 mb-2">Decides when "today" starts for your tasks and reminders.</p>

        <div
          v-if="timezoneMismatch"
          class="mb-2 flex items-center justify-between gap-2 rounded-xl bg-sunny-100 p-3 text-sm text-charcoal-700"
          role="status"
        >
          <span>This device is on {{ deviceTz.replace(/_/g, ' ') }}, but Sunwise is using {{ settings.timezone.replace(/_/g, ' ') }}.</span>
          <button
            type="button"
            @click="useDeviceTimezone"
            :disabled="saving === 'timezone'"
            class="font-semibold text-sage-800 underline flex-shrink-0 py-1"
          >
            Switch
          </button>
        </div>

        <select
          id="care-timezone"
          v-model="timezoneDraft"
          @change="saveTimezone(timezoneDraft)"
          :disabled="saving === 'timezone'"
          class="input text-sm"
        >
          <option v-if="deviceTz" :value="deviceTz">Use this device's time zone ({{ deviceTz.replace(/_/g, ' ') }})</option>
          <option v-for="tz in timeZones" :key="tz" :value="tz">{{ tz.replace(/_/g, ' ') }}</option>
        </select>
      </div>

      <!-- Climate notes -->
      <div>
        <label for="care-climate" class="block text-sm font-semibold text-charcoal-600 mb-1">Your climate and home</label>
        <p class="text-xs text-charcoal-500 mb-2">
          Things that change how fast soil dries, like dry air, heating or a sunny window. Sent to the AI with every plan.
        </p>
        <textarea
          id="care-climate"
          v-model="climateDraft"
          rows="3"
          maxlength="500"
          class="input text-sm resize-none"
          placeholder="Colorado Front Range, dry winter air, forced-air heat"
        ></textarea>
        <div class="flex items-center justify-between mt-2">
          <span class="text-xs text-charcoal-500">{{ climateDraft.length }}/500</span>
          <button
            type="button"
            @click="saveClimate"
            :disabled="!climateDirty || saving === 'climate'"
            class="btn-primary text-sm py-2 px-4"
          >
            {{ saving === 'climate' ? 'Saving...' : 'Save notes' }}
          </button>
        </div>
      </div>

      <!-- Fertilizer method -->
      <fieldset>
        <legend class="block text-sm font-semibold text-charcoal-600 mb-1">How you fertilize</legend>
        <p class="text-xs text-charcoal-500 mb-2">
          With granules, fertilizing is its own task every few months and never replaces a watering.
        </p>
        <div class="space-y-2">
          <label
            class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors focus-within:ring-2 focus-within:ring-sage-600"
            :class="settings.fertilizer_method === 'separate' ? 'border-sage-600 bg-sage-50' : 'border-cream-300 hover:border-sage-300'"
          >
            <input
              type="radio"
              name="fertilizer-method"
              value="separate"
              :checked="settings.fertilizer_method === 'separate'"
              :disabled="saving === 'fertilizer'"
              @change="setFertilizerMethod('separate')"
              class="mt-1 accent-sage-700"
            >
            <span>
              <span class="block text-sm font-semibold text-charcoal-700">Slow-release granules (like Osmocote)</span>
              <span class="block text-xs text-charcoal-500">Sprinkled on the soil about every 3 to 4 months in the growing season.</span>
            </span>
          </label>
          <label
            class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors focus-within:ring-2 focus-within:ring-sage-600"
            :class="settings.fertilizer_method === 'mixed' ? 'border-sage-600 bg-sage-50' : 'border-cream-300 hover:border-sage-300'"
          >
            <input
              type="radio"
              name="fertilizer-method"
              value="mixed"
              :checked="settings.fertilizer_method === 'mixed'"
              :disabled="saving === 'fertilizer'"
              @change="setFertilizerMethod('mixed')"
              class="mt-1 accent-sage-700"
            >
            <span>
              <span class="block text-sm font-semibold text-charcoal-700">Liquid mixed into water</span>
              <span class="block text-xs text-charcoal-500">Fertilizing counts as a watering, so the next water task moves out.</span>
            </span>
          </label>
        </div>
      </fieldset>

      <!-- Winter rest -->
      <div class="flex items-center justify-between gap-4">
        <div>
          <p id="winter-rest-label" class="text-sm font-semibold text-charcoal-600">Pause fertilizing September to February</p>
          <p id="winter-rest-desc" class="text-xs text-charcoal-500">Most houseplants rest in fall and winter and don't need feeding.</p>
        </div>
        <button
          type="button"
          role="switch"
          :aria-checked="settings.winter_rest"
          aria-labelledby="winter-rest-label"
          aria-describedby="winter-rest-desc"
          @click="toggleWinterRest"
          :disabled="saving === 'winter'"
          class="relative w-12 h-7 rounded-full transition-colors flex-shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-sage-600 focus-visible:ring-offset-2"
          :class="settings.winter_rest ? 'bg-sage-600' : 'bg-charcoal-300'"
        >
          <span
            class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-all"
            :class="settings.winter_rest ? 'left-6' : 'left-1'"
          ></span>
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
// Care tips for one task, loaded inline the first time the task is opened.
import { ref, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'

const props = defineProps({
  taskId: { type: [Number, String], required: true },
  plantName: { type: String, default: '' }
})

const api = useApi()
const loading = ref(false)
const error = ref(null)
const recommendations = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const response = await api.get(`/tasks/${props.taskId}/recommendations`, { timeout: 45000 })
    recommendations.value = response.recommendations
  } catch (e) {
    error.value = e.message || "Couldn't load care tips."
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div aria-live="polite">
    <!-- Loading -->
    <div v-if="loading" class="bg-plant-50 rounded-xl p-4 border border-plant-100">
      <div class="flex items-center gap-3">
        <div class="w-5 h-5 border-2 border-plant-600 border-t-transparent rounded-full animate-spin" aria-hidden="true"></div>
        <span class="text-sm text-plant-800">Getting care tips for {{ plantName || 'this plant' }}...</span>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 rounded-xl p-4 border border-red-100">
      <p class="text-sm text-red-800">{{ error }}</p>
      <button type="button" @click="load" class="mt-2 text-sm font-semibold text-red-800 underline py-1">Try again</button>
    </div>

    <!-- Tips -->
    <div v-else-if="recommendations" class="bg-plant-50 rounded-xl p-4 border border-plant-100 space-y-3">
      <div class="flex items-center gap-2 text-xs text-plant-700">
        <svg v-if="recommendations.source === 'ai'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
        </svg>
        <span>{{ recommendations.source === 'ai' ? 'AI tips for ' + plantName : 'Care tips' }}</span>
      </div>

      <p class="font-medium text-charcoal-700">{{ recommendations.summary }}</p>

      <div v-if="recommendations.amount || recommendations.timing" class="flex flex-wrap gap-2 text-xs">
        <span v-if="recommendations.amount" class="bg-white px-2 py-1 rounded-lg border border-plant-200">
          {{ recommendations.amount }}
        </span>
        <span v-if="recommendations.timing" class="bg-white px-2 py-1 rounded-lg border border-plant-200">
          {{ recommendations.timing }}
        </span>
      </div>

      <div v-if="recommendations.steps?.length" class="space-y-2">
        <p class="text-xs font-semibold text-charcoal-500 uppercase tracking-wide">Steps</p>
        <ol class="space-y-1.5 text-sm text-charcoal-600">
          <li v-for="(step, index) in recommendations.steps" :key="index" class="flex gap-2">
            <span class="flex-shrink-0 w-5 h-5 bg-plant-200 text-plant-900 rounded-full text-xs flex items-center justify-center font-medium" aria-hidden="true">{{ index + 1 }}</span>
            <span>{{ step }}</span>
          </li>
        </ol>
      </div>

      <div v-if="recommendations.warnings?.length" class="bg-yellow-50 rounded-lg p-3 border border-yellow-200">
        <p class="text-xs font-semibold text-yellow-900 mb-1">Watch out</p>
        <ul class="space-y-1 text-sm text-yellow-900">
          <li v-for="(warning, index) in recommendations.warnings" :key="index">{{ warning }}</li>
        </ul>
      </div>

      <div v-if="recommendations.tips?.length" class="text-sm text-charcoal-600">
        <p class="text-xs font-semibold text-charcoal-500 mb-1">Tips</p>
        <ul class="space-y-1">
          <li v-for="(tip, index) in recommendations.tips" :key="index">{{ tip }}</li>
        </ul>
      </div>
    </div>
  </div>
</template>

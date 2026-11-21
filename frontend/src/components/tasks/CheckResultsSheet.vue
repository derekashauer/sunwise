<script setup>
// One place to read what a check found. Opened from the "See notes" button
// on the single toast shown after a check, so a check with several notes
// no longer stacks pop-ups over the task list.
//
// Mounted once in App.vue and opened with window.$checkResults.show({...}),
// because the task row that saved the check may already have left the list.
import { ref, onMounted, onUnmounted } from 'vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { useApi } from '@/composables/useApi'

const api = useApi()

const visible = ref(false)
const plantName = ref('')
const plantId = ref(null)
const insights = ref([])
const onUndo = ref(null)
const applying = ref(null)
const applied = ref({})

function show(payload) {
  plantName.value = payload.plantName || 'This plant'
  plantId.value = payload.plantId || null
  // Things to act on first, then neutral notes, then good news
  const rank = { urgent: 0, warning: 0, info: 1, success: 2 }
  insights.value = [...(payload.insights || [])]
    .sort((a, b) => (rank[a.type] ?? 1) - (rank[b.type] ?? 1))
  onUndo.value = payload.onUndo || null
  applying.value = null
  applied.value = {}
  visible.value = true
}

function close() {
  visible.value = false
}

async function undo() {
  const fn = onUndo.value
  close()
  if (fn) await fn()
}

function intervalSuggestion(insight) {
  const s = insight.suggestion
  if (s && s.action === 'adjust_watering' && s.interval_days) return s
  return null
}

async function applyInterval(index, suggestion) {
  if (!plantId.value || applying.value !== null) return
  applying.value = index
  try {
    await api.post(`/plants/${plantId.value}/chat/apply-action`, {
      action: {
        type: 'update_care_schedule',
        task_type: suggestion.task_type || 'water',
        frequency_days: suggestion.interval_days
      }
    })
    applied.value = { ...applied.value, [index]: true }
    window.$toast?.success(`${plantName.value} will be watered every ${suggestion.interval_days} days`)
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't change the schedule. Try again.")
  } finally {
    applying.value = null
  }
}

function dotClass(type) {
  if (type === 'warning' || type === 'urgent') return 'bg-terracotta-600'
  if (type === 'success') return 'bg-sage-600'
  return 'bg-charcoal-400'
}

onMounted(() => {
  window.$checkResults = { show, close }
})

onUnmounted(() => {
  delete window.$checkResults
})
</script>

<template>
  <BaseModal
    :visible="visible"
    :title="`Check notes: ${plantName}`"
    variant="sheet"
    @close="close"
  >
    <ul class="space-y-3">
      <li
        v-for="(insight, index) in insights"
        :key="index"
        class="flex gap-3 items-start"
      >
        <span
          class="mt-2 w-2.5 h-2.5 rounded-full flex-shrink-0"
          :class="dotClass(insight.type)"
          aria-hidden="true"
        ></span>
        <div class="flex-1 min-w-0">
          <p class="text-charcoal-700 leading-snug">{{ insight.message }}</p>
          <p
            v-if="insight.suggestion && insight.suggestion.details && !intervalSuggestion(insight)"
            class="text-sm text-charcoal-500 mt-1"
          >
            {{ insight.suggestion.details }}
          </p>
          <button
            v-if="intervalSuggestion(insight)"
            type="button"
            class="mt-2 btn-secondary text-sm py-2 px-3"
            :disabled="applying !== null || applied[index]"
            @click="applyInterval(index, intervalSuggestion(insight))"
          >
            <template v-if="applied[index]">Schedule updated</template>
            <template v-else-if="applying === index">Saving...</template>
            <template v-else>Water every {{ intervalSuggestion(insight).interval_days }} days</template>
          </button>
        </div>
      </li>
    </ul>

    <template #footer>
      <div class="flex gap-3">
        <button v-if="onUndo" type="button" class="btn-ghost flex-1" @click="undo">
          Undo check
        </button>
        <button type="button" class="btn-primary flex-1" @click="close">
          Done
        </button>
      </div>
    </template>
  </BaseModal>
</template>

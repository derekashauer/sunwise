<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useApi } from '@/composables/useApi'
import BaseModal from '@/components/common/BaseModal.vue'
import { todayLocal, parseLocalDate, formatShortDate } from '@/utils/dates'
import { getTaskLabel } from '@/constants/taskTypes'

const props = defineProps({
  plantId: { type: [Number, String], required: true },
  visible: { type: Boolean, default: false }
})

// logged: (entry, { completedTaskId }) so the parent can refresh its tasks
const emit = defineEmits(['close', 'logged'])

const api = useApi()
const loading = ref(false)
const actionTypes = ref({ preset: [], custom: [] })
const selectedAction = ref('')
const notes = ref('')
const performedOn = ref(todayLocal())
const imageFile = ref(null)
const imagePreview = ref(null)
const showNewAction = ref(false)
const newActionName = ref('')
const newActionIcon = ref('')
const creatingAction = ref(false)

const allActions = computed(() => [
  ...actionTypes.value.preset,
  ...actionTypes.value.custom
])

const maxDate = computed(() => todayLocal())

onMounted(async () => {
  await loadActionTypes()
})

watch(() => props.visible, (visible) => {
  if (visible) performedOn.value = todayLocal()
})

async function loadActionTypes() {
  try {
    actionTypes.value = await api.get('/action-types')
  } catch (e) {
    console.error('Failed to load action types:', e)
  }
}

function clearPreview() {
  if (imagePreview.value) {
    URL.revokeObjectURL(imagePreview.value)
  }
  imagePreview.value = null
}

onBeforeUnmount(clearPreview)

function handleImageSelect(event) {
  const file = event.target.files[0]
  if (!file) return

  if (!file.type.startsWith('image/')) {
    window.$toast?.error('Please pick an image file')
    return
  }

  clearPreview()
  imageFile.value = file
  imagePreview.value = URL.createObjectURL(file)
}

function removeImage() {
  imageFile.value = null
  clearPreview()
}

async function createCustomAction() {
  if (!newActionName.value.trim()) return

  creatingAction.value = true
  try {
    const response = await api.post('/action-types', {
      name: newActionName.value.trim(),
      icon: newActionIcon.value || null
    })
    actionTypes.value.custom.push(response.action_type)
    selectedAction.value = response.action_type.value
    showNewAction.value = false
    newActionName.value = ''
    newActionIcon.value = ''
    window.$toast?.success('Custom action added')
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't add that action.")
  } finally {
    creatingAction.value = false
  }
}

async function submit() {
  if (!selectedAction.value) {
    window.$toast?.error('Pick what you did first')
    return
  }

  loading.value = true
  try {
    const formData = new FormData()
    formData.append('action', selectedAction.value)
    if (notes.value.trim()) {
      formData.append('notes', notes.value.trim())
    }
    // Today uses the current time on the server. An earlier day is sent as a
    // plain local date.
    if (performedOn.value && performedOn.value !== todayLocal()) {
      formData.append('performed_at', performedOn.value)
    }
    if (imageFile.value) {
      formData.append('image', imageFile.value)
    }

    const response = await api.postForm(`/plants/${props.plantId}/care-log`, formData)
    const label = getActionLabel(selectedAction.value)
    const when = performedOn.value !== todayLocal()
      ? ` for ${formatShortDate(parseLocalDate(performedOn.value))}`
      : ''
    if (response.completed_task_id) {
      window.$toast?.success(`${label} logged${when}. The matching ${getTaskLabel(selectedAction.value).toLowerCase()} task is marked done.`, { duration: 6000 })
    } else {
      window.$toast?.success(`${label} logged${when}`)
    }
    emit('logged', response.care_log_entry, { completedTaskId: response.completed_task_id || null })
    close()
  } catch (e) {
    window.$toast?.error(e.message || "Couldn't save that. Try again.")
  } finally {
    loading.value = false
  }
}

function close() {
  selectedAction.value = ''
  notes.value = ''
  performedOn.value = todayLocal()
  imageFile.value = null
  clearPreview()
  showNewAction.value = false
  emit('close')
}

function getActionLabel(action) {
  const found = allActions.value.find(a => a.value === action)
  return found?.label || action
}
</script>

<template>
  <BaseModal
    :visible="visible"
    title="Log care"
    close-label="Close log care"
    body-class="p-4 space-y-4"
    @close="close"
  >
    <!-- Action type -->
    <fieldset>
      <legend class="block text-sm font-medium text-charcoal-600 mb-2">What did you do?</legend>
      <div class="grid grid-cols-3 gap-2">
        <button
          v-for="action in allActions"
          :key="action.value"
          type="button"
          @click="selectedAction = action.value"
          :aria-pressed="selectedAction === action.value"
          class="p-3 rounded-xl border-2 text-center transition-all"
          :class="selectedAction === action.value
            ? 'border-plant-600 bg-plant-50'
            : 'border-gray-200 hover:border-gray-300'"
        >
          <span class="text-xl block mb-1" aria-hidden="true">{{ action.icon }}</span>
          <span class="text-xs" :class="selectedAction === action.value ? 'text-plant-800 font-semibold' : 'text-charcoal-600'">
            {{ action.label }}
          </span>
        </button>
        <button
          type="button"
          @click="showNewAction = true"
          class="p-3 rounded-xl border-2 border-dashed border-gray-300 text-center hover:border-gray-400"
        >
          <span class="text-xl block mb-1" aria-hidden="true">+</span>
          <span class="text-xs text-charcoal-600">Add new</span>
        </button>
      </div>
    </fieldset>

    <!-- New action form -->
    <div v-if="showNewAction" class="p-3 bg-gray-50 rounded-xl space-y-3">
      <p class="text-sm font-medium text-charcoal-600">Create a custom action</p>
      <div class="flex gap-2">
        <label for="new-action-icon" class="sr-only">Icon</label>
        <input
          id="new-action-icon"
          v-model="newActionIcon"
          type="text"
          class="input w-16 text-center text-xl"
          placeholder="🌿"
          maxlength="4"
        >
        <label for="new-action-name" class="sr-only">Action name</label>
        <input
          id="new-action-name"
          v-model="newActionName"
          type="text"
          class="input flex-1"
          placeholder="Action name..."
          maxlength="50"
        >
      </div>
      <div class="flex gap-2">
        <button type="button" @click="showNewAction = false" class="btn-secondary flex-1 text-sm">
          Cancel
        </button>
        <button
          type="button"
          @click="createCustomAction"
          :disabled="!newActionName.trim() || creatingAction"
          class="btn-primary flex-1 text-sm"
        >
          {{ creatingAction ? 'Adding...' : 'Add' }}
        </button>
      </div>
    </div>

    <!-- When -->
    <div>
      <label for="care-date" class="block text-sm font-medium text-charcoal-600 mb-1">When</label>
      <input
        id="care-date"
        v-model="performedOn"
        type="date"
        :max="maxDate"
        class="input"
      >
    </div>

    <!-- Photo -->
    <div>
      <p class="block text-sm font-medium text-charcoal-600 mb-2">Photo (optional)</p>
      <div v-if="imagePreview" class="relative">
        <img :src="imagePreview" class="w-full h-48 object-cover rounded-xl" alt="Photo to attach">
        <button
          type="button"
          @click="removeImage"
          class="absolute top-2 right-2 p-2 bg-black/60 rounded-full text-white hover:bg-black/75"
          aria-label="Remove photo"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div v-else>
        <input
          type="file"
          accept="image/*"
          capture="environment"
          @change="handleImageSelect"
          class="sr-only"
          id="care-photo"
        >
        <label
          for="care-photo"
          class="flex items-center justify-center gap-2 p-4 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-gray-400"
        >
          <svg class="w-5 h-5 text-charcoal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span class="text-sm text-charcoal-600">Add photo</span>
        </label>
      </div>
    </div>

    <!-- Notes -->
    <div>
      <label for="care-notes" class="block text-sm font-medium text-charcoal-600 mb-1">Notes (optional)</label>
      <textarea
        id="care-notes"
        v-model="notes"
        rows="3"
        class="input resize-none"
        placeholder="Any observations or details..."
      ></textarea>
    </div>

    <template #footer>
      <button
        type="button"
        @click="submit"
        :disabled="!selectedAction || loading"
        class="btn-primary w-full"
        :class="{ 'opacity-50 cursor-not-allowed': !selectedAction || loading }"
      >
        <span v-if="loading" class="flex items-center justify-center gap-2">
          <span class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
          Saving...
        </span>
        <span v-else>Log care</span>
      </button>
    </template>
  </BaseModal>
</template>

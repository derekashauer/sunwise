<script setup>
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'

const props = defineProps({
  plantId: { type: [Number, String], required: true },
  visible: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'logged'])

const api = useApi()
const loading = ref(false)
const actionTypes = ref({ preset: [], custom: [] })
const selectedAction = ref('')
const notes = ref('')
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

onMounted(async () => {
  await loadActionTypes()
})

async function loadActionTypes() {
  try {
    const response = await api.get('/action-types')
    actionTypes.value = response
  } catch (e) {
    console.error('Failed to load action types:', e)
  }
}

function handleImageSelect(event) {
  const file = event.target.files[0]
  if (!file) return

  if (!file.type.startsWith('image/')) {
    window.$toast?.error('Please select an image file')
    return
  }

  imageFile.value = file
  imagePreview.value = URL.createObjectURL(file)
}

function removeImage() {
  imageFile.value = null
  imagePreview.value = null
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
    window.$toast?.success('Custom action created!')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to create action')
  } finally {
    creatingAction.value = false
  }
}

async function submit() {
  if (!selectedAction.value) {
    window.$toast?.error('Please select an action type')
    return
  }

  loading.value = true
  try {
    const formData = new FormData()
    formData.append('action', selectedAction.value)
    if (notes.value.trim()) {
      formData.append('notes', notes.value.trim())
    }
    if (imageFile.value) {
      formData.append('image', imageFile.value)
    }

    const response = await api.postForm(`/plants/${props.plantId}/care-log`, formData)
    window.$toast?.success('Care logged!')
    emit('logged', response.care_log_entry)
    close()
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to log care')
  } finally {
    loading.value = false
  }
}

function close() {
  selectedAction.value = ''
  notes.value = ''
  imageFile.value = null
  imagePreview.value = null
  showNewAction.value = false
  emit('close')
}

function getActionIcon(action) {
  const found = allActions.value.find(a => a.value === action)
  return found?.icon || '📝'
}

function getActionLabel(action) {
  const found = allActions.value.find(a => a.value === action)
  return found?.label || action
}
</script>

<template>
  <div v-if="visible" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
    <div class="bg-white rounded-t-3xl w-full max-w-lg max-h-[85vh] overflow-auto safe-bottom">
      <div class="p-4 border-b sticky top-0 bg-white">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold">Log Care</h3>
          <button @click="close" class="p-2 -mr-2 text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <div class="p-4 space-y-4">
        <!-- Action Type Selection -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">What did you do?</label>
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="action in allActions"
              :key="action.value"
              type="button"
              @click="selectedAction = action.value"
              class="p-3 rounded-xl border-2 text-center transition-all"
              :class="selectedAction === action.value
                ? 'border-plant-500 bg-plant-50'
                : 'border-gray-200 hover:border-gray-300'"
            >
              <span class="text-xl block mb-1">{{ action.icon }}</span>
              <span class="text-xs" :class="selectedAction === action.value ? 'text-plant-700' : 'text-gray-600'">
                {{ action.label }}
              </span>
            </button>
            <!-- Add New Action Button -->
            <button
              type="button"
              @click="showNewAction = true"
              class="p-3 rounded-xl border-2 border-dashed border-gray-300 text-center hover:border-gray-400"
            >
              <span class="text-xl block mb-1">+</span>
              <span class="text-xs text-gray-500">Add New</span>
            </button>
          </div>
        </div>

        <!-- New Action Form -->
        <div v-if="showNewAction" class="p-3 bg-gray-50 rounded-xl space-y-3">
          <p class="text-sm font-medium text-gray-700">Create Custom Action</p>
          <div class="flex gap-2">
            <input
              v-model="newActionIcon"
              type="text"
              class="input w-16 text-center text-xl"
              placeholder="Icon"
              maxlength="4"
            >
            <input
              v-model="newActionName"
              type="text"
              class="input flex-1"
              placeholder="Action name..."
              maxlength="50"
            >
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              @click="showNewAction = false"
              class="btn-secondary flex-1 text-sm"
            >
              Cancel
            </button>
            <button
              type="button"
              @click="createCustomAction"
              :disabled="!newActionName.trim() || creatingAction"
              class="btn-primary flex-1 text-sm"
            >
              {{ creatingAction ? 'Creating...' : 'Create' }}
            </button>
          </div>
        </div>

        <!-- Photo Upload -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Photo (optional)</label>
          <div v-if="imagePreview" class="relative">
            <img :src="imagePreview" class="w-full h-48 object-cover rounded-xl" alt="Preview">
            <button
              @click="removeImage"
              class="absolute top-2 right-2 p-1 bg-black/50 rounded-full text-white hover:bg-black/70"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
              class="hidden"
              id="care-photo"
            >
            <label
              for="care-photo"
              class="flex items-center justify-center gap-2 p-4 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-gray-400"
            >
              <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <span class="text-sm text-gray-500">Add photo</span>
            </label>
          </div>
        </div>

        <!-- Notes -->
        <div>
          <label for="care-notes" class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
          <textarea
            id="care-notes"
            v-model="notes"
            rows="3"
            class="input resize-none"
            placeholder="Any observations or details..."
          ></textarea>
        </div>
      </div>

      <!-- Submit -->
      <div class="p-4 border-t">
        <button
          @click="submit"
          :disabled="!selectedAction || loading"
          class="btn-primary w-full"
          :class="{ 'opacity-50 cursor-not-allowed': !selectedAction || loading }"
        >
          <span v-if="loading" class="flex items-center justify-center gap-2">
            <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
            Logging...
          </span>
          <span v-else>Log Care</span>
        </button>
      </div>
    </div>
  </div>
</template>

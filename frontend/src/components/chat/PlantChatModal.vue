<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { useApi } from '@/composables/useApi'
import ChatMessage from './ChatMessage.vue'
import ActionCard from './ActionCard.vue'

const props = defineProps({
  plant: { type: Object, required: true },
  visible: { type: Boolean, default: false }
})

const emit = defineEmits(['close', 'plant-updated'])

const api = useApi()
const messages = ref([])
const inputMessage = ref('')
const loading = ref(false)
const loadingHistory = ref(true)
const pendingActions = ref([])
const messagesContainer = ref(null)

// AI provider settings
const aiSettings = ref(null)
const selectedProvider = ref(null)

onMounted(async () => {
  await Promise.all([
    loadChatHistory(),
    loadAiSettings()
  ])
})

watch(() => props.visible, (visible) => {
  if (visible) {
    loadChatHistory()
    nextTick(() => scrollToBottom())
  }
})

async function loadAiSettings() {
  try {
    aiSettings.value = await api.get('/settings/ai')
    selectedProvider.value = aiSettings.value.default_provider || 'openai'
  } catch (e) {
    console.error('Failed to load AI settings:', e)
    selectedProvider.value = 'openai'
  }
}

async function loadChatHistory() {
  loadingHistory.value = true
  try {
    const response = await api.get(`/plants/${props.plant.id}/chat`)
    messages.value = response.messages || []

    // Extract any pending actions from the last assistant message
    if (messages.value.length > 0) {
      const lastMsg = messages.value[messages.value.length - 1]
      if (lastMsg.role === 'assistant' && lastMsg.suggested_actions?.length > 0) {
        pendingActions.value = lastMsg.suggested_actions
      }
    }
  } catch (e) {
    console.error('Failed to load chat history:', e)
  } finally {
    loadingHistory.value = false
    nextTick(() => scrollToBottom())
  }
}

async function sendMessage() {
  const text = inputMessage.value.trim()
  if (!text || loading.value) return

  inputMessage.value = ''
  loading.value = true
  pendingActions.value = []

  // Add user message to UI immediately
  messages.value.push({
    role: 'user',
    content: text,
    created_at: new Date().toISOString()
  })
  nextTick(() => scrollToBottom())

  try {
    const response = await api.post(`/plants/${props.plant.id}/chat`, {
      message: text,
      provider: selectedProvider.value
    })

    // Add assistant response
    messages.value.push({
      role: 'assistant',
      content: response.response,
      provider: response.provider,
      created_at: new Date().toISOString()
    })

    // Set pending actions
    if (response.suggested_actions?.length > 0) {
      pendingActions.value = response.suggested_actions
    }
  } catch (e) {
    messages.value.push({
      role: 'assistant',
      content: 'Sorry, I encountered an error. Please try again.',
      created_at: new Date().toISOString()
    })
    window.$toast?.error(e.message)
  } finally {
    loading.value = false
    nextTick(() => scrollToBottom())
  }
}

async function applyAction(action) {
  try {
    const response = await api.post(`/plants/${props.plant.id}/chat/apply-action`, { action })
    if (response.success) {
      window.$toast?.success(`${action.type.replace('_', ' ')} updated!`)
      // Remove from pending actions
      pendingActions.value = pendingActions.value.filter(a => a !== action)
      // Notify parent to refresh plant data
      emit('plant-updated')
    }
  } catch (e) {
    window.$toast?.error(e.message)
  }
}

function dismissAction(action) {
  pendingActions.value = pendingActions.value.filter(a => a !== action)
}

function scrollToBottom() {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

function handleKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    sendMessage()
  }
}

const hasAnyKey = computed(() => {
  return aiSettings.value?.has_claude_key || aiSettings.value?.has_openai_key
})

const canUseProvider = computed(() => {
  if (!aiSettings.value) return false
  if (selectedProvider.value === 'openai') return aiSettings.value.has_openai_key
  if (selectedProvider.value === 'claude') return aiSettings.value.has_claude_key
  return false
})
</script>

<template>
  <div
    v-if="visible"
    class="fixed inset-0 bg-black/50 flex flex-col z-50"
    @click.self="emit('close')"
  >
    <div class="bg-white flex flex-col h-full safe-top safe-bottom">
      <!-- Header -->
      <header class="flex items-center gap-3 px-4 py-3 border-b bg-white">
        <button @click="emit('close')" class="btn-ghost p-2 -ml-2">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <div class="flex-1 min-w-0">
          <h2 class="font-semibold text-gray-900 truncate">Chat about {{ plant.name }}</h2>
          <p class="text-xs text-gray-500">{{ plant.species || 'Unknown species' }}</p>
        </div>

        <!-- Provider selector -->
        <select
          v-if="hasAnyKey"
          v-model="selectedProvider"
          class="text-sm border rounded-lg px-2 py-1 bg-gray-50"
        >
          <option v-if="aiSettings?.has_openai_key" value="openai">GPT-5.2</option>
          <option v-if="aiSettings?.has_claude_key" value="claude">Claude</option>
        </select>
      </header>

      <!-- No API key warning -->
      <div v-if="!loadingHistory && !hasAnyKey" class="flex-1 flex items-center justify-center p-6">
        <div class="text-center max-w-sm">
          <div class="w-16 h-16 mx-auto mb-4 bg-yellow-100 rounded-full flex items-center justify-center">
            <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <h3 class="font-semibold text-gray-900 mb-2">API Key Required</h3>
          <p class="text-gray-500 text-sm mb-4">
            To chat with AI about your plants, you need to add an API key in Settings.
          </p>
          <router-link to="/settings" class="btn-primary" @click="emit('close')">
            Go to Settings
          </router-link>
        </div>
      </div>

      <!-- Messages -->
      <div
        v-else
        ref="messagesContainer"
        class="flex-1 overflow-y-auto p-4 space-y-4"
      >
        <!-- Loading history -->
        <div v-if="loadingHistory" class="flex justify-center py-8">
          <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
        </div>

        <!-- Empty state -->
        <div v-else-if="messages.length === 0" class="text-center py-8">
          <div class="w-16 h-16 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
            <svg class="w-8 h-8 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
          </div>
          <h3 class="font-semibold text-gray-900 mb-2">Start a conversation</h3>
          <p class="text-gray-500 text-sm max-w-xs mx-auto">
            Ask about care tips, report issues, or get help identifying your plant.
          </p>
        </div>

        <!-- Message list -->
        <template v-else>
          <ChatMessage
            v-for="(msg, index) in messages"
            :key="index"
            :message="msg"
          />
        </template>

        <!-- Pending actions -->
        <div v-if="pendingActions.length > 0" class="space-y-2 pt-2">
          <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Suggested Updates</p>
          <ActionCard
            v-for="(action, index) in pendingActions"
            :key="index"
            :action="action"
            @apply="applyAction(action)"
            @dismiss="dismissAction(action)"
          />
        </div>

        <!-- Loading indicator -->
        <div v-if="loading" class="flex items-start gap-3">
          <div class="w-8 h-8 rounded-full bg-plant-100 flex items-center justify-center flex-shrink-0">
            <div class="w-4 h-4 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
          </div>
          <div class="bg-gray-100 rounded-2xl rounded-tl-sm px-4 py-2 max-w-[80%]">
            <span class="text-gray-500 text-sm">Thinking...</span>
          </div>
        </div>
      </div>

      <!-- Input -->
      <div v-if="hasAnyKey" class="border-t bg-white p-4">
        <div class="flex gap-2">
          <textarea
            v-model="inputMessage"
            @keydown="handleKeydown"
            :disabled="loading || !canUseProvider"
            rows="1"
            class="flex-1 input resize-none py-2"
            placeholder="Ask about your plant..."
          ></textarea>
          <button
            @click="sendMessage"
            :disabled="!inputMessage.trim() || loading || !canUseProvider"
            class="btn-primary px-4"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
            </svg>
          </button>
        </div>
        <p v-if="!canUseProvider" class="text-xs text-red-500 mt-1">
          No API key for {{ selectedProvider }}. Add one in Settings or switch providers.
        </p>
      </div>
    </div>
  </div>
</template>

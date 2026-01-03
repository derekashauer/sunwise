<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useApi } from '@/composables/useApi'

const router = useRouter()
const auth = useAuthStore()
const api = useApi()

const notificationsEnabled = ref(false)
const notificationsLoading = ref(false)

// AI Settings
const aiSettings = ref(null)
const aiLoading = ref(true)
const savingKey = ref(null)

// Key inputs
const claudeKeyInput = ref('')
const openaiKeyInput = ref('')
const showClaudeInput = ref(false)
const showOpenaiInput = ref(false)

onMounted(async () => {
  if ('Notification' in window && Notification.permission === 'granted') {
    notificationsEnabled.value = true
  }
  await loadAiSettings()
})

async function loadAiSettings() {
  aiLoading.value = true
  try {
    aiSettings.value = await api.get('/settings/ai')
  } catch (e) {
    console.error('Failed to load AI settings:', e)
    aiSettings.value = {
      default_provider: 'openai',
      has_claude_key: false,
      has_openai_key: false
    }
  } finally {
    aiLoading.value = false
  }
}

async function toggleNotifications() {
  if (notificationsEnabled.value) {
    window.$toast?.info('Disable notifications in your browser settings')
    return
  }

  // Check if notifications are supported
  if (!('Notification' in window)) {
    window.$toast?.error('Notifications not supported on this device/browser')
    return
  }

  // Check if already denied
  if (Notification.permission === 'denied') {
    window.$toast?.error('Notifications were previously blocked. Enable in browser settings.')
    return
  }

  notificationsLoading.value = true
  try {
    const permission = await Notification.requestPermission()
    if (permission === 'granted') {
      notificationsEnabled.value = true
      window.$toast?.success('Notifications enabled!')
    } else {
      window.$toast?.error('Notifications permission denied')
    }
  } catch (e) {
    console.error('Notification error:', e)
    window.$toast?.error('Failed to enable notifications: ' + (e.message || 'Unknown error'))
  } finally {
    notificationsLoading.value = false
  }
}

async function saveClaudeKey() {
  if (!claudeKeyInput.value.trim()) return
  savingKey.value = 'claude'
  try {
    const response = await api.post('/settings/ai/claude-key', {
      api_key: claudeKeyInput.value.trim()
    })
    if (response.success) {
      window.$toast?.success('Claude API key saved!')
      claudeKeyInput.value = ''
      showClaudeInput.value = false
      await loadAiSettings()
    }
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to save API key')
  } finally {
    savingKey.value = null
  }
}

async function saveOpenaiKey() {
  if (!openaiKeyInput.value.trim()) return
  savingKey.value = 'openai'
  try {
    const response = await api.post('/settings/ai/openai-key', {
      api_key: openaiKeyInput.value.trim()
    })
    if (response.success) {
      window.$toast?.success('OpenAI API key saved!')
      openaiKeyInput.value = ''
      showOpenaiInput.value = false
      await loadAiSettings()
    }
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to save API key')
  } finally {
    savingKey.value = null
  }
}

async function removeClaudeKey() {
  if (!confirm('Remove your Claude API key?')) return
  try {
    await api.delete('/settings/ai/claude-key')
    window.$toast?.success('Claude API key removed')
    await loadAiSettings()
  } catch (e) {
    window.$toast?.error(e.message)
  }
}

async function removeOpenaiKey() {
  if (!confirm('Remove your OpenAI API key?')) return
  try {
    await api.delete('/settings/ai/openai-key')
    window.$toast?.success('OpenAI API key removed')
    await loadAiSettings()
  } catch (e) {
    window.$toast?.error(e.message)
  }
}

async function setDefaultProvider(provider) {
  try {
    await api.put('/settings/ai/default-provider', { provider })
    aiSettings.value.default_provider = provider
    window.$toast?.success(`Default AI set to ${provider === 'openai' ? 'GPT-5.2' : 'Claude'}`)
  } catch (e) {
    window.$toast?.error(e.message)
  }
}

function logout() {
  auth.logout()
  router.push('/login')
}

const canSetClaudeDefault = computed(() => aiSettings.value?.has_claude_key)
const canSetOpenaiDefault = computed(() => aiSettings.value?.has_openai_key)
</script>

<template>
  <div class="page-container">
    <h1 class="page-title">Settings</h1>

    <!-- User info -->
    <div class="card p-4 mb-6">
      <h2 class="font-semibold text-gray-900 mb-3">Account</h2>
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 bg-plant-100 rounded-full flex items-center justify-center">
          <span class="text-plant-600 font-semibold text-lg">
            {{ auth.user?.email?.charAt(0).toUpperCase() }}
          </span>
        </div>
        <div>
          <p class="font-medium text-gray-900">{{ auth.user?.email }}</p>
          <p class="text-sm text-gray-500">Member since {{ new Date(auth.user?.created_at).toLocaleDateString() }}</p>
        </div>
      </div>
    </div>

    <!-- AI Settings -->
    <div class="card p-4 mb-6">
      <h2 class="font-semibold text-gray-900 mb-3">AI Settings</h2>

      <div v-if="aiLoading" class="flex justify-center py-4">
        <div class="w-6 h-6 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
      </div>

      <div v-else class="space-y-4">
        <!-- Default provider -->
        <div>
          <p class="text-sm font-medium text-gray-700 mb-2">Preferred AI Provider</p>
          <div class="flex gap-2">
            <button
              @click="setDefaultProvider('openai')"
              :disabled="!canSetOpenaiDefault"
              class="flex-1 px-3 py-2 rounded-xl border-2 text-sm transition-all"
              :class="aiSettings?.default_provider === 'openai'
                ? 'border-plant-500 bg-plant-50 text-plant-700'
                : canSetOpenaiDefault
                  ? 'border-gray-200 hover:border-gray-300 text-gray-700'
                  : 'border-gray-100 text-gray-400 cursor-not-allowed'"
            >
              GPT-5.2
            </button>
            <button
              @click="setDefaultProvider('claude')"
              :disabled="!canSetClaudeDefault"
              class="flex-1 px-3 py-2 rounded-xl border-2 text-sm transition-all"
              :class="aiSettings?.default_provider === 'claude'
                ? 'border-plant-500 bg-plant-50 text-plant-700'
                : canSetClaudeDefault
                  ? 'border-gray-200 hover:border-gray-300 text-gray-700'
                  : 'border-gray-100 text-gray-400 cursor-not-allowed'"
            >
              Claude
            </button>
          </div>
        </div>

        <!-- OpenAI Key -->
        <div class="pt-3 border-t">
          <div class="flex items-center justify-between mb-2">
            <div>
              <p class="font-medium text-gray-900">OpenAI API Key</p>
              <p class="text-xs text-gray-500">For ChatGPT 5.2</p>
            </div>
            <span
              v-if="aiSettings?.has_openai_key"
              class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full"
            >
              Configured
            </span>
          </div>

          <div v-if="aiSettings?.has_openai_key && !showOpenaiInput" class="flex items-center gap-2">
            <span class="text-sm text-gray-500 flex-1">Key saved</span>
            <button @click="showOpenaiInput = true" class="text-sm text-plant-600 hover:underline">
              Update
            </button>
            <button @click="removeOpenaiKey" class="text-sm text-red-600 hover:underline">
              Remove
            </button>
          </div>

          <div v-else class="space-y-2">
            <input
              v-model="openaiKeyInput"
              type="password"
              class="input text-sm"
              placeholder="sk-..."
            >
            <div class="flex gap-2">
              <button
                @click="saveOpenaiKey"
                :disabled="!openaiKeyInput.trim() || savingKey === 'openai'"
                class="btn-primary text-sm px-3 py-1.5"
              >
                <span v-if="savingKey === 'openai'" class="flex items-center gap-1">
                  <div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                  Saving...
                </span>
                <span v-else>Save Key</span>
              </button>
              <button
                v-if="showOpenaiInput && aiSettings?.has_openai_key"
                @click="showOpenaiInput = false; openaiKeyInput = ''"
                class="btn-secondary text-sm px-3 py-1.5"
              >
                Cancel
              </button>
            </div>
            <p class="text-xs text-gray-500">
              Get your key at
              <a href="https://platform.openai.com/api-keys" target="_blank" class="text-plant-600 hover:underline">
                platform.openai.com
              </a>
            </p>
          </div>
        </div>

        <!-- Claude Key -->
        <div class="pt-3 border-t">
          <div class="flex items-center justify-between mb-2">
            <div>
              <p class="font-medium text-gray-900">Claude API Key</p>
              <p class="text-xs text-gray-500">For Claude Opus 4.5</p>
            </div>
            <span
              v-if="aiSettings?.has_claude_key"
              class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full"
            >
              Configured
            </span>
          </div>

          <div v-if="aiSettings?.has_claude_key && !showClaudeInput" class="flex items-center gap-2">
            <span class="text-sm text-gray-500 flex-1">Key saved</span>
            <button @click="showClaudeInput = true" class="text-sm text-plant-600 hover:underline">
              Update
            </button>
            <button @click="removeClaudeKey" class="text-sm text-red-600 hover:underline">
              Remove
            </button>
          </div>

          <div v-else class="space-y-2">
            <input
              v-model="claudeKeyInput"
              type="password"
              class="input text-sm"
              placeholder="sk-ant-..."
            >
            <div class="flex gap-2">
              <button
                @click="saveClaudeKey"
                :disabled="!claudeKeyInput.trim() || savingKey === 'claude'"
                class="btn-primary text-sm px-3 py-1.5"
              >
                <span v-if="savingKey === 'claude'" class="flex items-center gap-1">
                  <div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                  Saving...
                </span>
                <span v-else>Save Key</span>
              </button>
              <button
                v-if="showClaudeInput && aiSettings?.has_claude_key"
                @click="showClaudeInput = false; claudeKeyInput = ''"
                class="btn-secondary text-sm px-3 py-1.5"
              >
                Cancel
              </button>
            </div>
            <p class="text-xs text-gray-500">
              Get your key at
              <a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-plant-600 hover:underline">
                console.anthropic.com
              </a>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Notifications -->
    <div class="card p-4 mb-6">
      <h2 class="font-semibold text-gray-900 mb-3">Notifications</h2>

      <div class="flex items-center justify-between">
        <div>
          <p class="font-medium text-gray-900">Push Notifications</p>
          <p class="text-sm text-gray-500">Get reminders for plant care tasks</p>
        </div>
        <button
          @click="toggleNotifications"
          :disabled="notificationsLoading"
          class="relative w-12 h-7 rounded-full transition-colors"
          :class="notificationsEnabled ? 'bg-plant-500' : 'bg-gray-300'"
        >
          <span
            class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-transform"
            :class="notificationsEnabled ? 'left-6' : 'left-1'"
          ></span>
        </button>
      </div>
    </div>

    <!-- App info -->
    <div class="card p-4 mb-6">
      <h2 class="font-semibold text-gray-900 mb-3">About</h2>

      <div class="space-y-3 text-sm">
        <div class="flex justify-between">
          <span class="text-gray-500">Version</span>
          <span class="text-gray-900">0.2.0</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">AI Providers</span>
          <span class="text-gray-900">GPT-5.2 / Claude</span>
        </div>
      </div>
    </div>

    <!-- Logout -->
    <button
      @click="logout"
      class="btn-secondary w-full text-red-600 border-red-200 hover:bg-red-50"
    >
      Log Out
    </button>
  </div>
</template>

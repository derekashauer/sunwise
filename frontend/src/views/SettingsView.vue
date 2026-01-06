<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useApi } from '@/composables/useApi'
import { APP_VERSION } from '@/config'

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

// Notification settings
const notificationSettings = ref(null)
const notificationLoading = ref(true)
const savingNotification = ref(false)

// Email digest form
const emailDigestEnabled = ref(false)
const emailDigestTime = ref('08:00')
const sendingTestEmail = ref(false)

// SMS form
const smsEnabled = ref(false)
const smsPhone = ref('')
const twilioSid = ref('')
const twilioToken = ref('')
const twilioPhone = ref('')
const showSmsSetup = ref(false)

// Public gallery
const gallerySettings = ref(null)
const galleryLoading = ref(true)
const savingGallery = ref(false)
const galleryEnabled = ref(false)
const galleryName = ref('')

// Task types
const taskTypes = ref([])
const taskTypesLoading = ref(true)
const savingTaskTypes = ref(false)

onMounted(async () => {
  if ('Notification' in window && Notification.permission === 'granted') {
    notificationsEnabled.value = true
  }
  await Promise.all([
    loadAiSettings(),
    loadNotificationSettings(),
    loadGallerySettings(),
    loadTaskTypes()
  ])
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

async function loadNotificationSettings() {
  notificationLoading.value = true
  try {
    notificationSettings.value = await api.get('/settings/notifications')
    emailDigestEnabled.value = !!notificationSettings.value.email_digest_enabled
    emailDigestTime.value = notificationSettings.value.email_digest_time || '08:00'
    smsEnabled.value = !!notificationSettings.value.sms_enabled
    smsPhone.value = notificationSettings.value.sms_phone || ''
    twilioPhone.value = notificationSettings.value.twilio_phone_number || ''
    // Don't load tokens for security
  } catch (e) {
    console.error('Failed to load notification settings:', e)
  } finally {
    notificationLoading.value = false
  }
}

async function loadGallerySettings() {
  galleryLoading.value = true
  try {
    gallerySettings.value = await api.get('/settings/public-gallery')
    galleryEnabled.value = !!gallerySettings.value.enabled
    galleryName.value = gallerySettings.value.name || ''
  } catch (e) {
    console.error('Failed to load gallery settings:', e)
  } finally {
    galleryLoading.value = false
  }
}

async function loadTaskTypes() {
  taskTypesLoading.value = true
  try {
    const response = await api.get('/settings/task-types')
    taskTypes.value = response.task_types || []
  } catch (e) {
    console.error('Failed to load task types:', e)
  } finally {
    taskTypesLoading.value = false
  }
}

async function toggleTaskType(taskType) {
  const task = taskTypes.value.find(t => t.type === taskType)
  if (!task) return

  savingTaskTypes.value = true
  try {
    const newEnabled = !task.enabled
    await api.put('/settings/task-types', {
      settings: { [taskType]: newEnabled }
    })
    task.enabled = newEnabled
    window.$toast?.success(`${task.label} ${newEnabled ? 'enabled' : 'disabled'}`)
  } catch (e) {
    window.$toast?.error('Failed to update task type')
  } finally {
    savingTaskTypes.value = false
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
    const modelName = provider === 'openai'
      ? aiSettings.value.available_models?.openai?.[aiSettings.value.openai_model] || 'OpenAI'
      : aiSettings.value.available_models?.claude?.[aiSettings.value.claude_model] || 'Claude'
    window.$toast?.success(`Default AI set to ${modelName}`)
  } catch (e) {
    window.$toast?.error(e.message)
  }
}

async function setModel(provider, model) {
  try {
    const response = await api.put('/settings/ai/model', { provider, model })
    if (provider === 'claude') {
      aiSettings.value.claude_model = model
    } else {
      aiSettings.value.openai_model = model
    }
    window.$toast?.success(`Model updated to ${response.model_name}`)
  } catch (e) {
    window.$toast?.error(e.message)
  }
}

async function saveEmailDigest() {
  savingNotification.value = true
  try {
    await api.put('/settings/notifications/email-digest', {
      enabled: emailDigestEnabled.value,
      time: emailDigestTime.value
    })
    window.$toast?.success('Email digest settings saved!')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to save settings')
  } finally {
    savingNotification.value = false
  }
}

async function sendTestEmail() {
  sendingTestEmail.value = true
  try {
    const response = await api.get('/cron/test-email')
    if (response.success) {
      window.$toast?.success(`Test email sent to ${response.email}!`)
    } else {
      window.$toast?.error(response.message || 'Failed to send test email')
    }
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to send test email')
  } finally {
    sendingTestEmail.value = false
  }
}

async function saveSmsSettings() {
  savingNotification.value = true
  try {
    await api.put('/settings/notifications/sms', {
      enabled: smsEnabled.value,
      phone: smsPhone.value,
      twilio_sid: twilioSid.value || undefined,
      twilio_token: twilioToken.value || undefined,
      twilio_phone: twilioPhone.value
    })
    window.$toast?.success('SMS settings saved!')
    showSmsSetup.value = false
    twilioSid.value = ''
    twilioToken.value = ''
    await loadNotificationSettings()
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to save settings')
  } finally {
    savingNotification.value = false
  }
}

async function saveGallerySettings() {
  savingGallery.value = true
  try {
    const response = await api.put('/settings/public-gallery', {
      enabled: galleryEnabled.value,
      name: galleryName.value
    })
    gallerySettings.value = response
    window.$toast?.success('Gallery settings saved!')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to save settings')
  } finally {
    savingGallery.value = false
  }
}

function copyGalleryLink() {
  if (!gallerySettings.value?.token) return
  const url = `${window.location.origin}/gallery/${gallerySettings.value.token}`
  navigator.clipboard.writeText(url)
  window.$toast?.success('Link copied to clipboard!')
}

function logout() {
  auth.logout()
  router.push('/login')
}

const canSetClaudeDefault = computed(() => aiSettings.value?.has_claude_key)
const canSetOpenaiDefault = computed(() => aiSettings.value?.has_openai_key)
const galleryUrl = computed(() => {
  if (!gallerySettings.value?.token) return null
  return `${window.location.origin}/gallery/${gallerySettings.value.token}`
})
</script>

<template>
  <div class="page-container">
    <h1 class="page-title">Settings</h1>

    <!-- User info -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/user-male-circle.png" alt="" class="w-6 h-6">
        Account
      </h2>
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 bg-sage-100 rounded-2xl flex items-center justify-center">
          <span class="text-sage-600 font-semibold text-lg">
            {{ auth.user?.email?.charAt(0).toUpperCase() }}
          </span>
        </div>
        <div>
          <p class="font-medium text-charcoal-700">{{ auth.user?.email }}</p>
          <p class="text-sm text-charcoal-400">Member since {{ new Date(auth.user?.created_at).toLocaleDateString() }}</p>
        </div>
      </div>
    </div>

    <!-- Quick Links -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/bookmark-ribbon.png" alt="" class="w-6 h-6">
        Quick Links
      </h2>
      <router-link
        to="/shopping-list"
        class="flex items-center justify-between p-3 -mx-1 rounded-xl hover:bg-cream-100 transition-colors"
      >
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-sunny-100 rounded-2xl flex items-center justify-center">
            <img src="https://img.icons8.com/doodle/48/shopping-cart.png" alt="" class="w-6 h-6">
          </div>
          <div>
            <p class="font-medium text-charcoal-700">Shopping List</p>
            <p class="text-sm text-charcoal-400">Plant supplies to buy</p>
          </div>
        </div>
        <svg class="w-5 h-5 text-charcoal-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </router-link>
      <router-link
        to="/pots"
        class="flex items-center justify-between p-3 -mx-1 rounded-xl hover:bg-cream-100 transition-colors"
      >
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-terracotta-50 rounded-2xl flex items-center justify-center">
            <img src="https://img.icons8.com/doodle/48/potted-plant--v1.png" alt="" class="w-6 h-6">
          </div>
          <div>
            <p class="font-medium text-charcoal-700">Pot Inventory</p>
            <p class="text-sm text-charcoal-400">Track available pots</p>
          </div>
        </div>
        <svg class="w-5 h-5 text-charcoal-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </router-link>
    </div>

    <!-- AI Settings -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/robot-2.png" alt="" class="w-6 h-6">
        AI Settings
      </h2>

      <div v-if="aiLoading" class="flex justify-center py-4">
        <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="loading" class="w-8 h-8 loading-watering-can">
      </div>

      <div v-else class="space-y-4">
        <!-- Default provider -->
        <div>
          <p class="text-sm font-medium text-charcoal-600 mb-2">Preferred AI Provider</p>
          <div class="flex gap-2">
            <button
              @click="setDefaultProvider('openai')"
              :disabled="!canSetOpenaiDefault"
              class="flex-1 px-3 py-2 rounded-xl border-2 text-sm transition-all"
              :class="aiSettings?.default_provider === 'openai'
                ? 'border-sage-500 bg-sage-50 text-sage-700'
                : canSetOpenaiDefault
                  ? 'border-cream-300 hover:border-charcoal-200 text-charcoal-600'
                  : 'border-cream-200 text-charcoal-300 cursor-not-allowed'"
            >
              OpenAI
            </button>
            <button
              @click="setDefaultProvider('claude')"
              :disabled="!canSetClaudeDefault"
              class="flex-1 px-3 py-2 rounded-xl border-2 text-sm transition-all"
              :class="aiSettings?.default_provider === 'claude'
                ? 'border-sage-500 bg-sage-50 text-sage-700'
                : canSetClaudeDefault
                  ? 'border-cream-300 hover:border-charcoal-200 text-charcoal-600'
                  : 'border-cream-200 text-charcoal-300 cursor-not-allowed'"
            >
              Claude
            </button>
          </div>
        </div>

        <!-- Model Selection -->
        <div class="pt-3 border-t border-cream-200">
          <p class="text-sm font-medium text-charcoal-600 mb-3">AI Models</p>
          <div class="space-y-3">
            <!-- OpenAI Model -->
            <div>
              <label class="block text-xs text-charcoal-400 mb-1">OpenAI Model</label>
              <select
                :value="aiSettings?.openai_model"
                @change="setModel('openai', $event.target.value)"
                :disabled="!aiSettings?.has_openai_key"
                class="input text-sm"
                :class="{ 'opacity-50 cursor-not-allowed': !aiSettings?.has_openai_key }"
              >
                <option
                  v-for="(label, model) in aiSettings?.available_models?.openai || {}"
                  :key="model"
                  :value="model"
                >
                  {{ label }}
                </option>
              </select>
            </div>
            <!-- Claude Model -->
            <div>
              <label class="block text-xs text-charcoal-400 mb-1">Claude Model</label>
              <select
                :value="aiSettings?.claude_model"
                @change="setModel('claude', $event.target.value)"
                :disabled="!aiSettings?.has_claude_key"
                class="input text-sm"
                :class="{ 'opacity-50 cursor-not-allowed': !aiSettings?.has_claude_key }"
              >
                <option
                  v-for="(label, model) in aiSettings?.available_models?.claude || {}"
                  :key="model"
                  :value="model"
                >
                  {{ label }}
                </option>
              </select>
            </div>
          </div>
        </div>

        <!-- OpenAI Key -->
        <div class="pt-3 border-t border-cream-200">
          <div class="flex items-center justify-between mb-2">
            <div>
              <p class="font-medium text-charcoal-700">OpenAI API Key</p>
              <p class="text-xs text-charcoal-400">For GPT-4o models</p>
            </div>
            <span
              v-if="aiSettings?.has_openai_key"
              class="text-xs text-sage-700 bg-sage-50 px-2 py-1 rounded-full"
            >
              Configured
            </span>
          </div>

          <div v-if="aiSettings?.has_openai_key && !showOpenaiInput" class="flex items-center gap-2">
            <span class="text-sm text-charcoal-400 flex-1">Key saved</span>
            <button @click="showOpenaiInput = true" class="text-sm text-sage-600 hover:underline">
              Update
            </button>
            <button @click="removeOpenaiKey" class="text-sm text-terracotta-600 hover:underline">
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
            <p class="text-xs text-charcoal-400">
              Get your key at
              <a href="https://platform.openai.com/api-keys" target="_blank" class="text-sage-600 hover:underline">
                platform.openai.com
              </a>
            </p>
          </div>
        </div>

        <!-- Claude Key -->
        <div class="pt-3 border-t border-cream-200">
          <div class="flex items-center justify-between mb-2">
            <div>
              <p class="font-medium text-charcoal-700">Claude API Key</p>
              <p class="text-xs text-charcoal-400">For Claude Opus 4.5</p>
            </div>
            <span
              v-if="aiSettings?.has_claude_key"
              class="text-xs text-sage-700 bg-sage-50 px-2 py-1 rounded-full"
            >
              Configured
            </span>
          </div>

          <div v-if="aiSettings?.has_claude_key && !showClaudeInput" class="flex items-center gap-2">
            <span class="text-sm text-charcoal-400 flex-1">Key saved</span>
            <button @click="showClaudeInput = true" class="text-sm text-sage-600 hover:underline">
              Update
            </button>
            <button @click="removeClaudeKey" class="text-sm text-terracotta-600 hover:underline">
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
            <p class="text-xs text-charcoal-400">
              Get your key at
              <a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-sage-600 hover:underline">
                console.anthropic.com
              </a>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Task Types -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-1 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/reminders.png" alt="" class="w-6 h-6">
        Care Task Types
      </h2>
      <p class="text-sm text-charcoal-400 mb-4">Choose which task types AI can create in your care plans</p>

      <div v-if="taskTypesLoading" class="flex justify-center py-4">
        <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="loading" class="w-8 h-8 loading-watering-can">
      </div>

      <div v-else class="space-y-2">
        <button
          v-for="task in taskTypes"
          :key="task.type"
          @click="toggleTaskType(task.type)"
          :disabled="savingTaskTypes"
          class="w-full flex items-center justify-between p-3 rounded-xl border-2 transition-colors"
          :class="task.enabled ? 'border-sage-200 bg-sage-50' : 'border-cream-200 bg-cream-100'"
        >
          <span class="text-sm font-medium" :class="task.enabled ? 'text-charcoal-700' : 'text-charcoal-400'">
            {{ task.label }}
          </span>
          <div
            class="w-10 h-6 rounded-full transition-colors relative"
            :class="task.enabled ? 'bg-sage-500' : 'bg-charcoal-200'"
          >
            <div
              class="absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform"
              :class="task.enabled ? 'translate-x-5' : 'translate-x-1'"
            ></div>
          </div>
        </button>
      </div>

      <p class="text-xs text-charcoal-400 mt-3">
        Disabled task types won't appear in AI-generated care plans. You can still manually log these activities.
      </p>
    </div>

    <!-- Push Notifications -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/alarm.png" alt="" class="w-6 h-6">
        Push Notifications
      </h2>

      <div class="flex items-center justify-between">
        <div>
          <p class="font-medium text-charcoal-700">Browser Notifications</p>
          <p class="text-sm text-charcoal-400">Get reminders for plant care tasks</p>
        </div>
        <button
          @click="toggleNotifications"
          :disabled="notificationsLoading"
          class="relative w-12 h-7 rounded-full transition-colors"
          :class="notificationsEnabled ? 'bg-sage-500' : 'bg-charcoal-200'"
        >
          <span
            class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-transform"
            :class="notificationsEnabled ? 'left-6' : 'left-1'"
          ></span>
        </button>
      </div>
    </div>

    <!-- Email Digest -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/new-post.png" alt="" class="w-6 h-6">
        Daily Email Digest
      </h2>

      <div v-if="notificationLoading" class="flex justify-center py-4">
        <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="loading" class="w-8 h-8 loading-watering-can">
      </div>

      <div v-else class="space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="font-medium text-charcoal-700">Enable Daily Digest</p>
            <p class="text-sm text-charcoal-400">Receive daily task summary via email</p>
          </div>
          <button
            @click="emailDigestEnabled = !emailDigestEnabled"
            class="relative w-12 h-7 rounded-full transition-colors"
            :class="emailDigestEnabled ? 'bg-sage-500' : 'bg-charcoal-200'"
          >
            <span
              class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-transform"
              :class="emailDigestEnabled ? 'left-6' : 'left-1'"
            ></span>
          </button>
        </div>

        <div v-if="emailDigestEnabled" class="space-y-3">
          <div>
            <label class="form-label">Send Time</label>
            <input
              v-model="emailDigestTime"
              type="time"
              class="input"
            >
            <p class="text-xs text-charcoal-400 mt-1">Time when daily digest will be sent</p>
          </div>

          <div class="flex gap-2">
            <button
              @click="saveEmailDigest"
              :disabled="savingNotification"
              class="btn-primary text-sm px-4 py-2"
            >
              <span v-if="savingNotification" class="flex items-center gap-1">
                <div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                Saving...
              </span>
              <span v-else>Save Email Settings</span>
            </button>
            <button
              @click="sendTestEmail"
              :disabled="sendingTestEmail"
              class="btn-secondary text-sm px-4 py-2"
            >
              <span v-if="sendingTestEmail" class="flex items-center gap-1">
                <div class="w-3 h-3 border-2 border-sage-500 border-t-transparent rounded-full animate-spin"></div>
                Sending...
              </span>
              <span v-else>Send Test Email</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- SMS Notifications -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/speech-bubble-with-dots.png" alt="" class="w-6 h-6">
        SMS Notifications
      </h2>

      <div v-if="notificationLoading" class="flex justify-center py-4">
        <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="loading" class="w-8 h-8 loading-watering-can">
      </div>

      <div v-else class="space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="font-medium text-charcoal-700">Enable SMS Alerts</p>
            <p class="text-sm text-charcoal-400">Requires your own Twilio account</p>
          </div>
          <button
            @click="smsEnabled = !smsEnabled"
            class="relative w-12 h-7 rounded-full transition-colors"
            :class="smsEnabled ? 'bg-sage-500' : 'bg-charcoal-200'"
          >
            <span
              class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-transform"
              :class="smsEnabled ? 'left-6' : 'left-1'"
            ></span>
          </button>
        </div>

        <div v-if="smsEnabled" class="space-y-3">
          <div>
            <label class="form-label">Your Phone Number</label>
            <input
              v-model="smsPhone"
              type="tel"
              class="input"
              placeholder="+1 555 123 4567"
            >
          </div>

          <div v-if="notificationSettings?.has_twilio_credentials && !showSmsSetup">
            <p class="text-sm text-sage-600 flex items-center gap-1">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              Twilio credentials configured
            </p>
            <button @click="showSmsSetup = true" class="text-sm text-sage-600 hover:underline mt-1">
              Update credentials
            </button>
          </div>

          <div v-else class="space-y-3 p-3 bg-cream-100 rounded-xl">
            <p class="text-sm font-medium text-charcoal-600">Twilio API Credentials</p>
            <div>
              <label class="block text-xs text-charcoal-400 mb-1">Account SID</label>
              <input
                v-model="twilioSid"
                type="text"
                class="input text-sm"
                placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
              >
            </div>
            <div>
              <label class="block text-xs text-charcoal-400 mb-1">Auth Token</label>
              <input
                v-model="twilioToken"
                type="password"
                class="input text-sm"
                placeholder="Your Twilio auth token"
              >
            </div>
            <div>
              <label class="block text-xs text-charcoal-400 mb-1">Twilio Phone Number</label>
              <input
                v-model="twilioPhone"
                type="tel"
                class="input text-sm"
                placeholder="+1 555 000 0000"
              >
            </div>
            <p class="text-xs text-charcoal-400">
              Get your credentials at
              <a href="https://www.twilio.com/console" target="_blank" class="text-sage-600 hover:underline">
                twilio.com/console
              </a>
            </p>
          </div>

          <button
            @click="saveSmsSettings"
            :disabled="savingNotification"
            class="btn-primary text-sm px-4 py-2"
          >
            <span v-if="savingNotification" class="flex items-center gap-1">
              <div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Saving...
            </span>
            <span v-else>Save SMS Settings</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Public Gallery -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/gallery.png" alt="" class="w-6 h-6">
        Public Plant Gallery
      </h2>

      <div v-if="galleryLoading" class="flex justify-center py-4">
        <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="loading" class="w-8 h-8 loading-watering-can">
      </div>

      <div v-else class="space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="font-medium text-charcoal-700">Enable Public Gallery</p>
            <p class="text-sm text-charcoal-400">Share your plants with a public link</p>
          </div>
          <button
            @click="galleryEnabled = !galleryEnabled"
            class="relative w-12 h-7 rounded-full transition-colors"
            :class="galleryEnabled ? 'bg-sage-500' : 'bg-charcoal-200'"
          >
            <span
              class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-transform"
              :class="galleryEnabled ? 'left-6' : 'left-1'"
            ></span>
          </button>
        </div>

        <div v-if="galleryEnabled" class="space-y-3">
          <div>
            <label class="form-label">Gallery Name</label>
            <input
              v-model="galleryName"
              type="text"
              class="input"
              placeholder="My Plant Collection"
            >
          </div>

          <div v-if="gallerySettings?.token" class="p-3 bg-sage-50 rounded-xl border border-sage-200">
            <p class="text-sm font-medium text-sage-800 mb-2">Share Your Gallery</p>
            <div class="flex items-stretch gap-2">
              <input
                :value="galleryUrl"
                readonly
                class="input text-sm flex-1 bg-white font-mono text-xs"
                @click="$event.target.select()"
              >
              <button
                @click="copyGalleryLink"
                class="btn-primary text-sm px-4 flex items-center gap-1.5"
              >
                <img src="https://img.icons8.com/doodle-line/48/copy.png" alt="" class="w-4 h-4 brightness-0 invert">
                Copy Link
              </button>
            </div>
            <p class="text-xs text-sage-600 mt-2">Anyone with this link can view your plant collection</p>
          </div>

          <button
            @click="saveGallerySettings"
            :disabled="savingGallery"
            class="btn-primary text-sm px-4 py-2"
          >
            <span v-if="savingGallery" class="flex items-center gap-1">
              <div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Saving...
            </span>
            <span v-else>Save Gallery Settings</span>
          </button>
        </div>
      </div>
    </div>

    <!-- App info -->
    <div class="card p-4 mb-6">
      <h2 class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/home-office.png" alt="" class="w-6 h-6">
        About
      </h2>

      <div class="space-y-3 text-sm">
        <div class="flex justify-between">
          <span class="text-charcoal-400">Version</span>
          <span class="text-charcoal-700 font-medium">{{ APP_VERSION }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-charcoal-400">AI Providers</span>
          <span class="text-charcoal-700">OpenAI / Claude</span>
        </div>
      </div>
    </div>

    <!-- Logout -->
    <button
      @click="logout"
      class="btn-secondary w-full text-terracotta-600 border-terracotta-200 hover:bg-terracotta-50"
    >
      Log Out
    </button>
  </div>
</template>

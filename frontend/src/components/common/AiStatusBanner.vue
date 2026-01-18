<script setup>
import { ref, onMounted, computed } from 'vue'
import { useApi } from '@/composables/useApi'

const api = useApi()
const aiStatus = ref(null)
const cronStatus = ref(null)
const loading = ref(true)
const dismissedAi = ref(false)
const dismissedCron = ref(false)

// Check if banners were dismissed recently (within 24 hours)
const aiDismissKey = 'ai_status_dismissed'
const cronDismissKey = 'cron_status_dismissed'

onMounted(async () => {
  // Check if dismissed
  const aiDismissedTime = localStorage.getItem(aiDismissKey)
  if (aiDismissedTime && Date.now() - parseInt(aiDismissedTime) < 24 * 60 * 60 * 1000) {
    dismissedAi.value = true
  }

  const cronDismissedTime = localStorage.getItem(cronDismissKey)
  if (cronDismissedTime && Date.now() - parseInt(cronDismissedTime) < 24 * 60 * 60 * 1000) {
    dismissedCron.value = true
  }

  await fetchStatuses()
})

async function fetchStatuses() {
  loading.value = true
  try {
    // Fetch both statuses in parallel
    const [ai, cron] = await Promise.all([
      api.get('/settings/ai/status').catch(() => null),
      api.get('/cron/status').catch(() => null)
    ])
    aiStatus.value = ai
    cronStatus.value = cron
  } catch (e) {
    console.error('Failed to fetch status:', e)
  } finally {
    loading.value = false
  }
}

function dismissAi() {
  dismissedAi.value = true
  localStorage.setItem(aiDismissKey, Date.now().toString())
}

function dismissCron() {
  dismissedCron.value = true
  localStorage.setItem(cronDismissKey, Date.now().toString())
}

const showAiBanner = computed(() => {
  if (loading.value || dismissedAi.value || !aiStatus.value) return false
  return ['error', 'warning', 'credit_issue', 'not_configured'].includes(aiStatus.value.status)
})

const showCronBanner = computed(() => {
  if (loading.value || dismissedCron.value || !cronStatus.value) return false
  return ['error', 'warning', 'not_configured'].includes(cronStatus.value.status)
})

function getBannerClass(status) {
  switch (status) {
    case 'error':
    case 'credit_issue':
      return 'bg-red-50 border-red-200 text-red-800'
    case 'warning':
      return 'bg-yellow-50 border-yellow-200 text-yellow-800'
    case 'not_configured':
      return 'bg-blue-50 border-blue-200 text-blue-800'
    default:
      return 'bg-gray-50 border-gray-200 text-gray-800'
  }
}

function getIconColor(status) {
  switch (status) {
    case 'error':
    case 'credit_issue':
      return 'text-red-500'
    case 'warning':
      return 'text-yellow-500'
    case 'not_configured':
      return 'text-blue-500'
    default:
      return 'text-gray-500'
  }
}

function formatTimeAgo(dateStr) {
  if (!dateStr) return 'never'
  const date = new Date(dateStr + 'Z') // Assume UTC
  const now = new Date()
  const diff = (now - date) / 1000 // seconds

  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)} minutes ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)} hours ago`
  return `${Math.floor(diff / 86400)} days ago`
}
</script>

<template>
  <div class="space-y-3">
    <!-- AI Status Banner -->
    <div v-if="showAiBanner" :class="['rounded-xl border p-3', getBannerClass(aiStatus.status)]">
      <div class="flex items-start gap-3">
        <!-- Icon -->
        <div :class="['flex-shrink-0 mt-0.5', getIconColor(aiStatus.status)]">
          <svg v-if="aiStatus.status === 'credit_issue'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <svg v-else-if="aiStatus.status === 'error'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <svg v-else-if="aiStatus.status === 'warning'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium">
            {{ aiStatus.status === 'not_configured' ? 'AI Features Disabled' : 'AI Status Alert' }}
          </p>
          <p class="text-sm mt-0.5 opacity-90">{{ aiStatus.status_message }}</p>

          <!-- Last error details for errors -->
          <p v-if="aiStatus.last_error && ['error', 'credit_issue'].includes(aiStatus.status)" class="text-xs mt-1 opacity-75">
            Last error: {{ aiStatus.last_error.message?.substring(0, 100) }}{{ aiStatus.last_error.message?.length > 100 ? '...' : '' }}
          </p>

          <!-- Action link -->
          <router-link
            to="/settings"
            class="inline-flex items-center gap-1 text-sm font-medium mt-2 hover:underline"
          >
            {{ aiStatus.status === 'not_configured' ? 'Add API Key' : 'View Settings' }}
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </router-link>
        </div>

        <!-- Dismiss button -->
        <button
          @click="dismissAi"
          class="flex-shrink-0 p-1 rounded-lg hover:bg-black/5 transition-colors"
          title="Dismiss for 24 hours"
        >
          <svg class="w-5 h-5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Cron Status Banner -->
    <div v-if="showCronBanner" :class="['rounded-xl border p-3', getBannerClass(cronStatus.status)]">
      <div class="flex items-start gap-3">
        <!-- Icon -->
        <div :class="['flex-shrink-0 mt-0.5', getIconColor(cronStatus.status)]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium">
            {{ cronStatus.status === 'not_configured' ? 'Email Reminders Not Running' : 'Scheduled Jobs Alert' }}
          </p>
          <p class="text-sm mt-0.5 opacity-90">{{ cronStatus.status_message }}</p>

          <!-- Last run info -->
          <p v-if="cronStatus.last_daily_reminder" class="text-xs mt-1 opacity-75">
            Last run: {{ formatTimeAgo(cronStatus.last_daily_reminder.ran_at) }}
            ({{ cronStatus.last_daily_reminder.emails_sent }} emails sent)
          </p>
        </div>

        <!-- Dismiss button -->
        <button
          @click="dismissCron"
          class="flex-shrink-0 p-1 rounded-lg hover:bg-black/5 transition-colors"
          title="Dismiss for 24 hours"
        >
          <svg class="w-5 h-5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>
  </div>
</template>

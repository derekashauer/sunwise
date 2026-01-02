<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const notificationsEnabled = ref(false)
const notificationsLoading = ref(false)

onMounted(async () => {
  if ('Notification' in window && Notification.permission === 'granted') {
    notificationsEnabled.value = true
  }
})

async function toggleNotifications() {
  if (notificationsEnabled.value) {
    // Can't programmatically disable, just inform user
    window.$toast?.info('Disable notifications in your browser settings')
    return
  }

  notificationsLoading.value = true
  try {
    const permission = await Notification.requestPermission()
    if (permission === 'granted') {
      notificationsEnabled.value = true
      window.$toast?.success('Notifications enabled!')
      // TODO: Subscribe to push notifications
    } else {
      window.$toast?.error('Notifications permission denied')
    }
  } catch (e) {
    window.$toast?.error('Failed to enable notifications')
  } finally {
    notificationsLoading.value = false
  }
}

function logout() {
  auth.logout()
  router.push('/login')
}
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
          <span class="text-gray-900">0.1.0</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Powered by</span>
          <span class="text-gray-900">Claude AI</span>
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

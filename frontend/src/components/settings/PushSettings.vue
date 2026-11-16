<script setup>
// Push reminders. Turning this on asks the browser for permission, subscribes
// this device with the server's key, and saves the subscription on the server.
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { isStandalone } from '@/pwa'

const api = useApi()

const busy = ref(false)
const subscribed = ref(false)
const message = ref('')
const permission = ref(typeof Notification !== 'undefined' ? Notification.permission : 'default')

const supported = typeof window !== 'undefined' &&
  'serviceWorker' in navigator &&
  'PushManager' in window &&
  'Notification' in window

const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) ||
  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

const needsInstall = computed(() => isIos && !isStandalone.value)

const statusText = computed(() => {
  if (!supported) {
    return needsInstall.value
      ? 'On iPhone, add Sunwise to your home screen first, then turn reminders on from there.'
      : "This browser can't receive push reminders."
  }
  if (permission.value === 'denied') {
    return 'Notifications are blocked for Sunwise. Allow them in your browser or phone settings, then come back here.'
  }
  if (subscribed.value) return 'On for this device. You get one reminder on days with plant care to do.'
  return 'Off for this device.'
})

// VAPID keys come as base64url text; the browser wants raw bytes.
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  return Uint8Array.from([...raw].map(char => char.charCodeAt(0)))
}

function withTimeout(promise, ms, text) {
  return Promise.race([
    promise,
    new Promise((_, reject) => setTimeout(() => reject(new Error(text)), ms))
  ])
}

async function getRegistration() {
  return withTimeout(
    navigator.serviceWorker.ready,
    10000,
    "The app's offline helper isn't running yet. Reload the page and try again."
  )
}

async function refreshState() {
  if (!supported) return
  permission.value = Notification.permission
  try {
    const registration = await navigator.serviceWorker.getRegistration()
    const subscription = await registration?.pushManager.getSubscription()
    subscribed.value = !!subscription && permission.value === 'granted'
  } catch (e) {
    subscribed.value = false
  }
}

async function enable() {
  message.value = ''
  if (!supported) return

  busy.value = true
  try {
    const { public_key: publicKey } = await api.get('/notifications/vapid-public-key')
    if (!publicKey) {
      message.value = "Push reminders aren't set up on the server yet. The daily email still works."
      return
    }

    const result = await Notification.requestPermission()
    permission.value = result
    if (result !== 'granted') {
      message.value = result === 'denied'
        ? 'Notifications were blocked. Allow them in your browser or phone settings to get reminders.'
        : 'Reminders stay off until you allow notifications.'
      return
    }

    const registration = await getRegistration()
    let subscription = await registration.pushManager.getSubscription()
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey)
      })
    }

    await api.post('/notifications/subscribe', { subscription: subscription.toJSON() })
    subscribed.value = true
    window.$toast?.success('Push reminders are on for this device')
  } catch (e) {
    message.value = e.message || "Couldn't turn on reminders. Try again."
  } finally {
    busy.value = false
  }
}

async function disable() {
  message.value = ''
  busy.value = true
  try {
    const registration = await navigator.serviceWorker.getRegistration()
    const subscription = await registration?.pushManager.getSubscription()
    if (subscription) {
      await subscription.unsubscribe()
    }
    await api.delete('/notifications/unsubscribe')
    subscribed.value = false
    window.$toast?.info('Push reminders are off for this device')
  } catch (e) {
    message.value = e.message || "Couldn't turn off reminders. Try again."
  } finally {
    busy.value = false
  }
}

function toggle() {
  if (busy.value) return
  if (subscribed.value) {
    disable()
  } else {
    enable()
  }
}

onMounted(refreshState)
</script>

<template>
  <section class="card p-4 mb-6" aria-labelledby="push-settings-title">
    <h2 id="push-settings-title" class="font-hand text-xl text-charcoal-700 mb-3 flex items-center gap-2">
      <img src="https://img.icons8.com/doodle/48/alarm.png" alt="" class="w-6 h-6">
      Push reminders
    </h2>

    <div class="flex items-center justify-between gap-4">
      <div>
        <p id="push-label" class="font-medium text-charcoal-700">Reminders on this device</p>
        <p id="push-status" class="text-sm text-charcoal-500" aria-live="polite">{{ statusText }}</p>
      </div>
      <button
        type="button"
        role="switch"
        :aria-checked="subscribed"
        aria-labelledby="push-label"
        aria-describedby="push-status"
        @click="toggle"
        :disabled="busy || !supported || (permission === 'denied' && !subscribed)"
        class="relative w-12 h-7 rounded-full transition-colors flex-shrink-0 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-sage-600 focus-visible:ring-offset-2"
        :class="subscribed ? 'bg-sage-600' : 'bg-charcoal-300'"
      >
        <span
          class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-all"
          :class="subscribed ? 'left-6' : 'left-1'"
        ></span>
      </button>
    </div>

    <p v-if="message" class="mt-3 text-sm text-red-800 bg-red-50 rounded-xl p-3" role="alert">{{ message }}</p>
  </section>
</template>

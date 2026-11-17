<script setup>
// Suggests adding Sunwise to the home screen, which is what makes offline use
// and push reminders work on phones. Dismissing it is remembered.
import { ref, computed } from 'vue'
import { installPromptEvent, isStandalone, promptInstall } from '@/pwa'

const STORAGE_KEY = 'sunwise-install-hint-dismissed'

function readDismissed() {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch (e) {
    return false
  }
}

const dismissed = ref(readDismissed())

const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) ||
  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

const mode = computed(() => {
  if (dismissed.value || isStandalone.value) return null
  if (installPromptEvent.value) return 'prompt'
  if (isIos) return 'ios'
  return null
})

function dismiss() {
  dismissed.value = true
  try {
    localStorage.setItem(STORAGE_KEY, '1')
  } catch (e) {
    // Storage blocked; the hint just comes back next time.
  }
}

async function install() {
  const accepted = await promptInstall()
  if (accepted) dismiss()
}
</script>

<template>
  <div
    v-if="mode"
    class="mb-4 flex items-start gap-3 rounded-2xl border-2 border-sage-200 bg-sage-50 p-3"
  >
    <img src="/icons/icon-192.png" alt="" class="w-10 h-10 rounded-xl flex-shrink-0">
    <div class="flex-1 min-w-0 text-sm text-charcoal-700">
      <p class="font-semibold">Add Sunwise to your home screen</p>
      <p v-if="mode === 'ios'" class="mt-0.5 text-charcoal-600">
        Tap the Share button
        <svg class="inline w-4 h-4 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0-12l-4 4m4-4l4 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" />
        </svg>
        in Safari, then "Add to Home Screen". It opens faster, works offline and can send reminders.
      </p>
      <p v-else class="mt-0.5 text-charcoal-600">It opens faster, works offline and can send reminders.</p>
      <button v-if="mode === 'prompt'" type="button" @click="install" class="btn-primary text-sm py-2 px-4 mt-2">
        Install
      </button>
    </div>
    <button
      type="button"
      @click="dismiss"
      class="p-2 -m-1 rounded-lg text-charcoal-500 hover:bg-sage-100 flex-shrink-0"
      aria-label="Don't show this again"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
  </div>
</template>

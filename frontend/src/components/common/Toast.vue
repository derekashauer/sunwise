<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const DEFAULT_DURATION = 3500
const MAX_VISIBLE = 3

const toasts = ref([])
let toastId = 0
const timers = new Map()

/**
 * Show a toast.
 * options: { type: 'success'|'error'|'info', duration: ms (0 keeps it open),
 *            action: { label, onClick } }
 * Returns the toast id so callers can dismiss it early.
 */
function show(message, options = {}) {
  const id = ++toastId
  const type = options.type || 'info'
  const action = options.action || null
  // Toasts with an action stay a little longer so there's time to tap it.
  const duration = options.duration ?? (action ? 5000 : DEFAULT_DURATION)

  toasts.value.push({ id, message, type, action })

  // Never let pop-ups pile up over the page: keep the newest few
  while (toasts.value.length > MAX_VISIBLE) {
    removeToast(toasts.value[0].id)
  }

  if (duration > 0) {
    timers.set(id, setTimeout(() => removeToast(id), duration))
  }
  return id
}

function removeToast(id) {
  clearTimeout(timers.get(id))
  timers.delete(id)
  toasts.value = toasts.value.filter(t => t.id !== id)
}

async function runAction(toast) {
  removeToast(toast.id)
  try {
    await toast.action.onClick?.()
  } catch (e) {
    show(e.message || 'That didn\'t work. Try again.', { type: 'error' })
  }
}

onMounted(() => {
  window.$toast = {
    show,
    dismiss: removeToast,
    success: (msg, opts = {}) => show(msg, { ...opts, type: 'success' }),
    error: (msg, opts = {}) => show(msg, { duration: 5000, ...opts, type: 'error' }),
    info: (msg, opts = {}) => show(msg, { ...opts, type: 'info' })
  }
})

onUnmounted(() => {
  timers.forEach(timer => clearTimeout(timer))
  timers.clear()
  delete window.$toast
})
</script>

<template>
  <div
    class="toast-region fixed left-4 right-4 z-[60] flex flex-col gap-2 pointer-events-none"
    role="status"
    aria-live="polite"
    aria-atomic="false"
  >
    <transition-group name="toast">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto p-4 rounded-xl shadow-lg w-full max-w-md mx-auto flex items-center gap-3 text-white"
        :class="{
          'bg-sage-700': toast.type === 'success',
          'bg-red-700': toast.type === 'error',
          'bg-charcoal-700': toast.type === 'info'
        }"
      >
        <!-- Success icon -->
        <svg v-if="toast.type === 'success'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>

        <!-- Error icon -->
        <svg v-else-if="toast.type === 'error'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>

        <!-- Info icon -->
        <svg v-else class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>

        <span class="text-sm font-medium flex-1 min-w-0">{{ toast.message }}</span>

        <button
          v-if="toast.action"
          type="button"
          @click="runAction(toast)"
          class="px-3 py-1.5 -my-1 rounded-lg text-sm font-bold underline underline-offset-2 hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white flex-shrink-0"
        >
          {{ toast.action.label }}
        </button>

        <button
          type="button"
          @click="removeToast(toast.id)"
          class="p-2 -m-1 hover:bg-white/20 rounded-lg flex-shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
          aria-label="Dismiss message"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </transition-group>
  </div>
</template>

<style scoped>
.toast-region {
  top: calc(env(safe-area-inset-top, 0px) + 1rem);
}

.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}

.toast-enter-from {
  opacity: 0;
  transform: translateY(-20px);
}

.toast-leave-to {
  opacity: 0;
  transform: translateX(100%);
}

@media (prefers-reduced-motion: reduce) {
  .toast-enter-active,
  .toast-leave-active {
    transition: opacity 0.15s linear;
  }

  .toast-enter-from,
  .toast-leave-to {
    transform: none;
  }
}
</style>

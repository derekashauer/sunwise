<script setup>
// Accessible modal used by every dialog in the app.
// - role="dialog" + aria-modal, labelled by its title
// - keeps keyboard focus inside while open and returns it afterwards
// - Escape and the backdrop ask to close (the parent decides, via `close`)
// - locks page scroll behind it
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  // 'sheet' slides up from the bottom on phones, 'center' is a centered card.
  variant: { type: String, default: 'sheet' },
  size: { type: String, default: 'md' },
  showClose: { type: Boolean, default: true },
  closeOnBackdrop: { type: Boolean, default: true },
  closeLabel: { type: String, default: 'Close' },
  bodyClass: { type: String, default: 'p-4' }
})

const emit = defineEmits(['close'])

// Unique per instance so each dialog's aria-labelledby points at its own title.
const titleId = `modal-title-${Math.random().toString(36).slice(2, 9)}`
const panel = ref(null)
let previouslyFocused = null

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])'
].join(',')

// Several modals can be open at once (a confirm on top of a form), so count
// them and only unlock scrolling when the last one closes.
function lockScroll() {
  const count = Number(document.body.dataset.modalCount || 0) + 1
  document.body.dataset.modalCount = String(count)
  document.body.style.overflow = 'hidden'
}

function unlockScroll() {
  const count = Math.max(0, Number(document.body.dataset.modalCount || 0) - 1)
  document.body.dataset.modalCount = String(count)
  if (count === 0) {
    document.body.style.overflow = ''
  }
}

function focusables() {
  if (!panel.value) return []
  return Array.from(panel.value.querySelectorAll(FOCUSABLE))
    .filter(el => el.offsetParent !== null || el === document.activeElement)
}

function requestClose() {
  emit('close')
}

function onKeydown(event) {
  if (!props.visible) return
  // Only the top-most modal reacts.
  const all = document.querySelectorAll('[data-base-modal]')
  if (all.length && all[all.length - 1] !== panel.value) return

  if (event.key === 'Escape') {
    event.stopPropagation()
    requestClose()
    return
  }

  if (event.key === 'Tab') {
    const items = focusables()
    if (items.length === 0) {
      event.preventDefault()
      panel.value?.focus()
      return
    }
    const first = items[0]
    const last = items[items.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }
}

async function onOpen() {
  previouslyFocused = document.activeElement
  lockScroll()
  document.addEventListener('keydown', onKeydown)
  await nextTick()
  const autofocus = panel.value?.querySelector('[autofocus]')
  const target = autofocus || focusables()[0] || panel.value
  target?.focus({ preventScroll: true })
}

function onClose() {
  document.removeEventListener('keydown', onKeydown)
  unlockScroll()
  if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
    previouslyFocused.focus({ preventScroll: true })
  }
  previouslyFocused = null
}

watch(() => props.visible, (visible, wasVisible) => {
  if (visible && !wasVisible) onOpen()
  if (!visible && wasVisible) onClose()
}, { immediate: true })

onBeforeUnmount(() => {
  if (props.visible) onClose()
})

const sizeClass = {
  sm: 'sm:max-w-sm',
  md: 'sm:max-w-md',
  lg: 'sm:max-w-lg'
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="visible"
        class="fixed inset-0 z-50 flex justify-center"
        :class="variant === 'center' ? 'items-center p-6' : 'items-end sm:items-center'"
      >
        <!-- Backdrop -->
        <div
          class="absolute inset-0 bg-black/50"
          aria-hidden="true"
          @click="closeOnBackdrop && requestClose()"
        ></div>

        <!-- Panel -->
        <div
          ref="panel"
          data-base-modal
          role="dialog"
          aria-modal="true"
          :aria-labelledby="title ? titleId : undefined"
          tabindex="-1"
          class="modal-panel relative bg-white w-full max-h-[90vh] flex flex-col overflow-hidden focus:outline-none"
          :class="[
            variant === 'center' ? 'rounded-2xl max-w-sm' : 'rounded-t-2xl sm:rounded-2xl',
            sizeClass[size] || sizeClass.md
          ]"
        >
          <div
            v-if="title || showClose || $slots.header"
            class="flex items-start justify-between gap-3 px-4 py-3 border-b bg-white"
          >
            <slot name="header">
              <div class="min-w-0">
                <h2 :id="titleId" class="font-semibold text-lg text-charcoal-700">{{ title }}</h2>
                <p v-if="subtitle" class="text-sm text-charcoal-400">{{ subtitle }}</p>
              </div>
            </slot>
            <button
              v-if="showClose"
              type="button"
              @click="requestClose"
              class="p-2 -mr-2 -mt-1 rounded-lg text-charcoal-400 hover:text-charcoal-600 hover:bg-cream-100 flex-shrink-0"
              :aria-label="closeLabel"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="flex-1 overflow-y-auto overscroll-contain" :class="bodyClass">
            <slot />
          </div>

          <div v-if="$slots.footer" class="border-t bg-white p-4 safe-bottom">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-active .modal-panel,
.modal-leave-active .modal-panel {
  transition: transform 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .modal-panel,
.modal-leave-to .modal-panel {
  transform: translateY(24px);
}

@media (prefers-reduced-motion: reduce) {
  .modal-enter-active .modal-panel,
  .modal-leave-active .modal-panel {
    transition: none;
  }

  .modal-enter-from .modal-panel,
  .modal-leave-to .modal-panel {
    transform: none;
  }
}
</style>

<script setup>
// Full-screen photo viewer. Escape, the close button or a tap outside the
// photo closes it, and focus returns to whatever opened it.
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  src: { type: String, default: '' },
  alt: { type: String, default: '' },
  caption: { type: String, default: '' }
})

const emit = defineEmits(['close'])

const closeButton = ref(null)
let previouslyFocused = null

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.stopPropagation()
    emit('close')
  } else if (event.key === 'Tab') {
    // Only one control inside, so keep focus on it.
    event.preventDefault()
    closeButton.value?.focus()
  }
}

watch(() => props.visible, async (visible) => {
  if (visible) {
    previouslyFocused = document.activeElement
    document.addEventListener('keydown', onKeydown)
    document.body.style.overflow = 'hidden'
    await nextTick()
    closeButton.value?.focus({ preventScroll: true })
  } else {
    cleanup()
  }
})

function cleanup() {
  document.removeEventListener('keydown', onKeydown)
  if (!Number(document.body.dataset.modalCount || 0)) {
    document.body.style.overflow = ''
  }
  previouslyFocused?.focus?.({ preventScroll: true })
  previouslyFocused = null
}

onBeforeUnmount(() => {
  if (props.visible) cleanup()
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="fixed inset-0 bg-black/85 flex items-center justify-center z-[70] p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="alt || caption || 'Photo'"
      @click.self="emit('close')"
    >
      <div class="relative max-w-lg w-full">
        <button
          ref="closeButton"
          type="button"
          @click="emit('close')"
          class="absolute -top-12 right-0 text-white/90 hover:text-white p-2 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
          aria-label="Close photo"
        >
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <img :src="src" :alt="alt" class="w-full max-h-[75vh] object-contain rounded-2xl shadow-2xl">
        <p v-if="caption" class="text-white text-center mt-3 font-medium">{{ caption }}</p>
      </div>
    </div>
  </Teleport>
</template>

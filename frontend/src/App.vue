<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTasksStore } from '@/stores/tasks'
import BottomNav from '@/components/common/BottomNav.vue'
import Toast from '@/components/common/Toast.vue'
import CheckResultsSheet from '@/components/tasks/CheckResultsSheet.vue'

const route = useRoute()
const auth = useAuthStore()
const tasks = useTasksStore()

const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine)

const showNav = computed(() => {
  const noNavRoutes = ['login', 'register', 'verify', 'sitter-guest', 'plant-share', 'public-gallery', 'accept-invite', 'not-found']
  return auth.isAuthenticated && !noNavRoutes.includes(route.name)
})

// Send anything done offline as soon as a connection is back.
async function sync() {
  if (!auth.isAuthenticated || !navigator.onLine) return
  try {
    await tasks.syncOutbox()
  } catch (e) {
    console.error('Offline sync failed:', e)
  }
}

function onOnline() {
  online.value = true
  sync()
}

function onOffline() {
  online.value = false
}

onMounted(() => {
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  tasks.refreshPendingSync()
})

onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline)
  window.removeEventListener('offline', onOffline)
})

// Sync once the user is known (on app start or right after logging in).
watch(() => auth.isAuthenticated, (isAuthed) => {
  if (isAuthed) sync()
}, { immediate: true })
</script>

<template>
  <div class="min-h-screen bg-cream-100">
    <div
      v-if="!online && auth.isAuthenticated"
      class="offline-banner sticky top-0 z-40 bg-charcoal-700 text-white text-sm text-center px-4 py-2"
      role="status"
    >
      You're offline. Showing your last saved list. Changes will sync when you're back online.
    </div>

    <router-view v-slot="{ Component }">
      <transition name="page" mode="out-in">
        <component :is="Component" />
      </transition>
    </router-view>

    <BottomNav v-if="showNav" />
    <Toast />
    <CheckResultsSheet />
  </div>
</template>

<style>
.offline-banner {
  padding-top: calc(env(safe-area-inset-top, 0px) + 0.5rem);
}

.page-enter-active,
.page-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.page-enter-from {
  opacity: 0;
  transform: translateY(8px);
}

.page-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .page-enter-active,
  .page-leave-active {
    transition: none;
  }

  .page-enter-from {
    transform: none;
  }
}
</style>

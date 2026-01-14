<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import BottomNav from '@/components/common/BottomNav.vue'
import Toast from '@/components/common/Toast.vue'

const route = useRoute()
const auth = useAuthStore()

const showNav = computed(() => {
  const noNavRoutes = ['login', 'register', 'verify', 'sitter-guest', 'plant-share', 'public-gallery', 'accept-invite']
  return auth.isAuthenticated && !noNavRoutes.includes(route.name)
})
</script>

<template>
  <div class="min-h-screen bg-cream-100">
    <router-view v-slot="{ Component }">
      <transition name="page" mode="out-in">
        <component :is="Component" />
      </transition>
    </router-view>

    <BottomNav v-if="showNav" />
    <Toast />
  </div>
</template>

<style>
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
</style>

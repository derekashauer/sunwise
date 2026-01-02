<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import BottomNav from '@/components/common/BottomNav.vue'
import Toast from '@/components/common/Toast.vue'

const route = useRoute()
const auth = useAuthStore()

const showNav = computed(() => {
  const noNavRoutes = ['login', 'register', 'verify', 'sitter-guest']
  return auth.isAuthenticated && !noNavRoutes.includes(route.name)
})
</script>

<template>
  <div class="min-h-screen bg-plant-50">
    <router-view v-slot="{ Component }">
      <transition name="fade" mode="out-in">
        <component :is="Component" />
      </transition>
    </router-view>

    <BottomNav v-if="showNav" />
    <Toast />
  </div>
</template>

<style>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>

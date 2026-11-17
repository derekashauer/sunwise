<script setup>
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="page-container flex flex-col items-center justify-center text-center">
    <img src="https://img.icons8.com/doodle/96/potted-plant--v1.png" alt="" class="w-20 h-20 mb-4 opacity-70">
    <h1 class="page-title">Page not found</h1>
    <p class="text-charcoal-600 mb-6">That page doesn't exist. It may have been moved, or the link is wrong.</p>
    <router-link :to="auth.isAuthenticated ? '/' : '/login'" class="btn-primary">
      {{ auth.isAuthenticated ? 'Back to Today' : 'Go to login' }}
    </router-link>
  </div>
</template>

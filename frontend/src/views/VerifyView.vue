<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    await auth.verifyMagicLink(route.params.token)
    router.replace('/')
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="min-h-screen flex flex-col items-center justify-center px-6 bg-plant-50">
    <div v-if="loading" class="text-center">
      <div class="w-12 h-12 border-2 border-plant-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
      <p class="text-gray-600">Verifying your login...</p>
    </div>

    <div v-else-if="error" class="text-center">
      <div class="w-16 h-16 mx-auto mb-4 bg-red-100 rounded-full flex items-center justify-center">
        <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </div>
      <h1 class="text-xl font-bold text-gray-900 mb-2">Verification Failed</h1>
      <p class="text-gray-500 mb-6">{{ error }}</p>
      <router-link to="/login" class="btn-primary">
        Back to Login
      </router-link>
    </div>
  </div>
</template>

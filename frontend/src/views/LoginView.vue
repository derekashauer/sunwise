<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { APP_VERSION } from '@/config'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')
const magicLinkSent = ref(false)
const useMagicLink = ref(false)

async function handleSubmit() {
  if (!email.value) return

  loading.value = true
  error.value = ''

  try {
    if (useMagicLink.value) {
      await auth.requestMagicLink(email.value)
      magicLinkSent.value = true
    } else {
      if (!password.value) {
        error.value = 'Password is required'
        loading.value = false
        return
      }
      await auth.login(email.value, password.value)
      const redirect = route.query.redirect || '/'
      router.push(redirect)
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex flex-col justify-center px-6 py-12 bg-plant-50">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <!-- Logo -->
      <div class="w-16 h-16 mx-auto mb-6 bg-plant-500 rounded-2xl flex items-center justify-center">
        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
        </svg>
      </div>

      <h1 class="text-2xl font-bold text-center text-gray-900">Welcome back</h1>
      <p class="mt-2 text-center text-gray-500">Sign in to manage your plants</p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <!-- Magic link sent confirmation -->
      <div v-if="magicLinkSent" class="card p-6 text-center">
        <div class="w-12 h-12 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
          <svg class="w-6 h-6 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
        </div>
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Check your email</h2>
        <p class="text-gray-500 text-sm">We sent a magic link to <strong>{{ email }}</strong></p>
        <button @click="magicLinkSent = false" class="mt-6 text-plant-600 text-sm font-medium">
          Use a different email
        </button>
      </div>

      <!-- Login form -->
      <form v-else @submit.prevent="handleSubmit" class="card p-6 space-y-5">
        <div v-if="error" class="p-3 bg-red-50 text-red-700 text-sm rounded-lg">
          {{ error }}
        </div>

        <div>
          <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="email"
            required
            class="input"
            placeholder="you@example.com"
          >
        </div>

        <div v-if="!useMagicLink">
          <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            class="input"
            placeholder="••••••••"
          >
        </div>

        <button
          type="submit"
          :disabled="loading"
          class="btn-primary w-full"
        >
          <span v-if="loading" class="flex items-center justify-center gap-2">
            <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
            {{ useMagicLink ? 'Sending...' : 'Signing in...' }}
          </span>
          <span v-else>{{ useMagicLink ? 'Send Magic Link' : 'Sign In' }}</span>
        </button>

        <button
          type="button"
          @click="useMagicLink = !useMagicLink"
          class="w-full text-center text-sm text-gray-500 hover:text-gray-700"
        >
          {{ useMagicLink ? 'Use password instead' : 'Sign in with magic link' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-gray-500">
        Don't have an account?
        <router-link to="/register" class="text-plant-600 font-medium hover:text-plant-700">
          Sign up
        </router-link>
      </p>

      <!-- Version number -->
      <p class="mt-8 text-center text-xs text-gray-400">v{{ APP_VERSION }}</p>
    </div>
  </div>
</template>

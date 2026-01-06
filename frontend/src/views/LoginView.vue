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
  <div class="min-h-screen flex flex-col justify-center px-6 py-12 bg-cream-100">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <!-- Logo -->
      <div class="w-20 h-20 mx-auto mb-6 bg-sage-100 rounded-3xl flex items-center justify-center shadow-sage">
        <img
          src="https://img.icons8.com/doodle/96/potted-plant--v1.png"
          alt="Sunwise"
          class="w-12 h-12"
        >
      </div>

      <h1 class="font-hand text-3xl text-center text-charcoal-700">Welcome back!</h1>
      <p class="mt-2 text-center text-charcoal-400">Sign in to care for your plants</p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <!-- Magic link sent confirmation -->
      <div v-if="magicLinkSent" class="card p-6 text-center">
        <div class="w-14 h-14 mx-auto mb-4 bg-sage-100 rounded-2xl flex items-center justify-center">
          <img
            src="https://img.icons8.com/doodle/48/new-post.png"
            alt="email"
            class="w-8 h-8"
          >
        </div>
        <h2 class="font-hand text-xl text-charcoal-700 mb-2">Check your email</h2>
        <p class="text-charcoal-400 text-sm">We sent a magic link to <strong class="text-charcoal-600">{{ email }}</strong></p>
        <button @click="magicLinkSent = false" class="mt-6 text-sage-600 text-sm font-medium hover:text-sage-700">
          Use a different email
        </button>
      </div>

      <!-- Login form -->
      <form v-else @submit.prevent="handleSubmit" class="card p-6 space-y-5">
        <div v-if="error" class="p-3 bg-terracotta-50 text-terracotta-700 text-sm rounded-xl border border-terracotta-200">
          {{ error }}
        </div>

        <div>
          <label for="email" class="form-label">Email</label>
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
          <label for="password" class="form-label">Password</label>
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
            <img
              src="https://img.icons8.com/doodle/48/watering-can.png"
              alt="loading"
              class="w-5 h-5 loading-watering-can"
            >
            {{ useMagicLink ? 'Sending...' : 'Signing in...' }}
          </span>
          <span v-else>{{ useMagicLink ? 'Send Magic Link' : 'Sign In' }}</span>
        </button>

        <button
          type="button"
          @click="useMagicLink = !useMagicLink"
          class="w-full text-center text-sm text-charcoal-400 hover:text-charcoal-600"
        >
          {{ useMagicLink ? 'Use password instead' : 'Sign in with magic link' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-charcoal-400">
        Don't have an account?
        <router-link to="/register" class="text-sage-600 font-medium hover:text-sage-700">
          Sign up
        </router-link>
      </p>

      <!-- Version number -->
      <p class="mt-8 text-center text-xs text-charcoal-300">v{{ APP_VERSION }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const confirmPassword = ref('')
const loading = ref(false)
const error = ref('')

async function handleSubmit() {
  error.value = ''

  if (password.value.length < 8) {
    error.value = 'Password must be at least 8 characters'
    return
  }

  if (password.value !== confirmPassword.value) {
    error.value = 'Passwords do not match'
    return
  }

  loading.value = true

  try {
    await auth.register(email.value, password.value)
    router.push('/')
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

      <h1 class="font-hand text-3xl text-center text-charcoal-700">Join Sunwise!</h1>
      <p class="mt-2 text-center text-charcoal-400">Start your plant care journey</p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <form @submit.prevent="handleSubmit" class="card p-6 space-y-5">
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

        <div>
          <label for="password" class="form-label">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            required
            class="input"
            placeholder="At least 8 characters"
          >
        </div>

        <div>
          <label for="confirm-password" class="form-label">Confirm Password</label>
          <input
            id="confirm-password"
            v-model="confirmPassword"
            type="password"
            autocomplete="new-password"
            required
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
            Creating account...
          </span>
          <span v-else class="flex items-center justify-center gap-2">
            <img
              src="https://img.icons8.com/doodle/48/sprout.png"
              alt=""
              class="w-5 h-5"
            >
            Create Account
          </span>
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-charcoal-400">
        Already have an account?
        <router-link to="/login" class="text-sage-600 font-medium hover:text-sage-700">
          Sign in
        </router-link>
      </p>
    </div>
  </div>
</template>

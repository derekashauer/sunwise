<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useApi } from '@/composables/useApi'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const api = useApi()

const loading = ref(true)
const accepting = ref(false)
const invitation = ref(null)
const error = ref(null)

onMounted(async () => {
  await loadInvitation()
})

async function loadInvitation() {
  loading.value = true
  error.value = null

  try {
    const token = route.params.token
    invitation.value = await api.get(`/invitations/${token}`)
  } catch (e) {
    console.error('Failed to load invitation:', e)
    error.value = e.message || 'Invitation not found or expired'
  } finally {
    loading.value = false
  }
}

async function acceptInvitation() {
  if (!auth.isAuthenticated) {
    // Redirect to login with return URL
    router.push({
      name: 'login',
      query: { redirect: route.fullPath }
    })
    return
  }

  accepting.value = true
  try {
    const token = route.params.token
    await api.post(`/invitations/${token}/accept`)
    window.$toast?.success('You\'ve joined the household!')
    router.push({ name: 'household' })
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to accept invitation')
  } finally {
    accepting.value = false
  }
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString()
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-b from-cream-50 to-sage-50">
    <div class="card p-6 w-full max-w-md text-center">
      <!-- Loading -->
      <div v-if="loading" class="py-12">
        <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="loading" class="w-12 h-12 mx-auto loading-watering-can">
        <p class="text-charcoal-400 mt-4">Loading invitation...</p>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="py-8">
        <img src="https://img.icons8.com/doodle/48/cancel.png" alt="" class="w-16 h-16 mx-auto mb-4 opacity-50">
        <h2 class="font-hand text-xl text-charcoal-600 mb-2">Invitation Not Found</h2>
        <p class="text-charcoal-400 mb-6">{{ error }}</p>
        <router-link to="/" class="btn-primary">
          Go to Dashboard
        </router-link>
      </div>

      <!-- Invitation details -->
      <div v-else-if="invitation">
        <img src="https://img.icons8.com/doodle/96/home--v1.png" alt="" class="w-20 h-20 mx-auto mb-4">

        <h1 class="font-hand text-2xl text-charcoal-700 mb-2">You're Invited!</h1>

        <p class="text-charcoal-600 mb-6">
          <span class="font-medium">{{ invitation.invited_by_name }}</span> has invited you to join
          <span class="font-medium">{{ invitation.household_name }}</span> on Sunwise.
        </p>

        <div v-if="invitation.shared_plants_count > 0" class="bg-sage-50 rounded-xl p-4 mb-6">
          <p class="text-sage-700">
            <span class="font-medium">{{ invitation.shared_plants_count }}</span>
            plant{{ invitation.shared_plants_count !== 1 ? 's' : '' }} will be shared with you
          </p>
        </div>

        <div class="space-y-3">
          <button
            @click="acceptInvitation"
            :disabled="accepting"
            class="btn-primary w-full"
          >
            <span v-if="accepting" class="flex items-center justify-center gap-2">
              <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Joining...
            </span>
            <span v-else-if="!auth.isAuthenticated">Login to Join</span>
            <span v-else>Join Household</span>
          </button>

          <p v-if="!auth.isAuthenticated" class="text-sm text-charcoal-400">
            Don't have an account?
            <router-link :to="{ name: 'register', query: { redirect: route.fullPath } }" class="text-sage-600 hover:underline">
              Sign up
            </router-link>
          </p>
        </div>

        <p class="text-xs text-charcoal-400 mt-6">
          Expires {{ formatDate(invitation.expires_at) }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'

const router = useRouter()
const api = useApi()

const loading = ref(true)
const households = ref([])
const plants = ref([])
const selectedHousehold = ref(null)
const members = ref([])
const sharedPlants = ref([])
const pendingInvitations = ref([])

// Create household
const showCreateModal = ref(false)
const newHouseholdName = ref('')
const creating = ref(false)

// Invite member
const showInviteModal = ref(false)
const inviteEmail = ref('')
const shareAllPlants = ref(true)
const selectedPlantIds = ref([])
const inviting = ref(false)

// Share plants modal
const showSharePlantsModal = ref(false)
const plantsToShare = ref([])
const sharing = ref(false)

onMounted(async () => {
  await Promise.all([loadHouseholds(), loadPlants()])
})

async function loadHouseholds() {
  loading.value = true
  try {
    const response = await api.get('/households')
    households.value = response.households || []

    // Auto-select first household
    if (households.value.length > 0 && !selectedHousehold.value) {
      await selectHousehold(households.value[0])
    }
  } catch (e) {
    console.error('Failed to load households:', e)
  } finally {
    loading.value = false
  }
}

async function loadPlants() {
  try {
    const response = await api.get('/plants')
    plants.value = response.plants || []
  } catch (e) {
    console.error('Failed to load plants:', e)
  }
}

async function selectHousehold(household) {
  selectedHousehold.value = household
  await Promise.all([
    loadMembers(household.id),
    loadSharedPlants(household.id),
    loadInvitations(household.id)
  ])
}

async function loadMembers(householdId) {
  try {
    const response = await api.get(`/households/${householdId}/members`)
    members.value = response.members || []
  } catch (e) {
    console.error('Failed to load members:', e)
  }
}

async function loadSharedPlants(householdId) {
  try {
    const response = await api.get(`/households/${householdId}/plants`)
    sharedPlants.value = response.plants || []
  } catch (e) {
    console.error('Failed to load shared plants:', e)
  }
}

async function loadInvitations(householdId) {
  try {
    const response = await api.get(`/households/${householdId}/invitations`)
    pendingInvitations.value = response.invitations || []
  } catch (e) {
    console.error('Failed to load invitations:', e)
  }
}

async function createHousehold() {
  if (!newHouseholdName.value.trim()) return

  creating.value = true
  try {
    const response = await api.post('/households', {
      name: newHouseholdName.value.trim()
    })
    households.value.push(response.household)
    await selectHousehold(response.household)
    showCreateModal.value = false
    newHouseholdName.value = ''
    window.$toast?.success('Household created!')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to create household')
  } finally {
    creating.value = false
  }
}

async function sendInvitation() {
  if (!inviteEmail.value.trim()) return

  inviting.value = true
  try {
    await api.post(`/households/${selectedHousehold.value.id}/invite`, {
      email: inviteEmail.value.trim(),
      share_all_plants: shareAllPlants.value,
      plant_ids: shareAllPlants.value ? [] : selectedPlantIds.value
    })
    await loadInvitations(selectedHousehold.value.id)
    showInviteModal.value = false
    inviteEmail.value = ''
    selectedPlantIds.value = []
    window.$toast?.success('Invitation sent!')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to send invitation')
  } finally {
    inviting.value = false
  }
}

async function revokeInvitation(invitationId) {
  if (!confirm('Cancel this invitation?')) return

  try {
    await api.delete(`/invitations/${invitationId}`)
    pendingInvitations.value = pendingInvitations.value.filter(i => i.id !== invitationId)
    window.$toast?.success('Invitation cancelled')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to cancel invitation')
  }
}

async function removeMember(userId) {
  const member = members.value.find(m => m.user_id === userId)
  if (!member) return

  const action = member.is_self ? 'leave this household' : `remove ${member.display_name || member.email}?`
  if (!confirm(`Are you sure you want to ${action}?`)) return

  try {
    await api.delete(`/households/${selectedHousehold.value.id}/members/${userId}`)
    if (member.is_self) {
      // Left household, go back to list
      selectedHousehold.value = null
      await loadHouseholds()
      window.$toast?.success('Left household')
    } else {
      await loadMembers(selectedHousehold.value.id)
      window.$toast?.success('Member removed')
    }
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to remove member')
  }
}

async function sharePlants() {
  if (plantsToShare.value.length === 0) return

  sharing.value = true
  try {
    await api.post(`/households/${selectedHousehold.value.id}/plants`, {
      plant_ids: plantsToShare.value
    })
    await loadSharedPlants(selectedHousehold.value.id)
    showSharePlantsModal.value = false
    plantsToShare.value = []
    window.$toast?.success('Plants shared!')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to share plants')
  } finally {
    sharing.value = false
  }
}

async function unsharePlant(plantId) {
  if (!confirm('Remove this plant from shared plants?')) return

  try {
    await api.delete(`/households/${selectedHousehold.value.id}/plants/${plantId}`)
    sharedPlants.value = sharedPlants.value.filter(p => p.id !== plantId)
    window.$toast?.success('Plant unshared')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to unshare plant')
  }
}

async function deleteHousehold() {
  if (!confirm('Delete this household? All members will lose access to shared plants.')) return

  try {
    await api.delete(`/households/${selectedHousehold.value.id}`)
    selectedHousehold.value = null
    await loadHouseholds()
    window.$toast?.success('Household deleted')
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to delete household')
  }
}

const myOwnedPlants = computed(() => {
  return plants.value.filter(p => p.is_owned === 1 || p.is_owned === '1' || p.is_owned === true)
})

const unsahredPlants = computed(() => {
  const sharedIds = new Set(sharedPlants.value.map(p => p.id))
  return myOwnedPlants.value.filter(p => !sharedIds.has(p.id))
})

const isOwner = computed(() => {
  return selectedHousehold.value?.is_owner
})

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString()
}
</script>

<template>
  <div class="page-container">
    <div class="flex items-center justify-between mb-6">
      <h1 class="page-title flex items-center gap-2">
        <img src="https://img.icons8.com/doodle/48/family--v1.png" alt="" class="w-8 h-8">
        Household
      </h1>
      <button
        v-if="!selectedHousehold"
        @click="showCreateModal = true"
        class="btn-primary text-sm px-4 py-2"
      >
        + Create Household
      </button>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="flex justify-center py-12">
      <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="loading" class="w-8 h-8 loading-watering-can">
    </div>

    <!-- No households -->
    <div v-else-if="households.length === 0" class="card p-8 text-center">
      <img src="https://img.icons8.com/doodle/96/family--v1.png" alt="" class="w-16 h-16 mx-auto mb-4 opacity-50">
      <h2 class="font-hand text-xl text-charcoal-600 mb-2">No Household Yet</h2>
      <p class="text-charcoal-400 mb-6">
        Create a household to share plant care with family members or roommates.
      </p>
      <button @click="showCreateModal = true" class="btn-primary">
        Create Your Household
      </button>
    </div>

    <!-- Household list (when no selection) -->
    <div v-else-if="!selectedHousehold" class="space-y-3">
      <button
        v-for="h in households"
        :key="h.id"
        @click="selectHousehold(h)"
        class="card p-4 w-full text-left hover:border-sage-300 transition-colors"
      >
        <div class="flex items-center justify-between">
          <div>
            <h3 class="font-medium text-charcoal-700">{{ h.name }}</h3>
            <p class="text-sm text-charcoal-400">
              {{ h.member_count }} member{{ h.member_count !== 1 ? 's' : '' }}
              <span v-if="h.is_owner" class="text-sage-600"> (Owner)</span>
            </p>
          </div>
          <svg class="w-5 h-5 text-charcoal-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </div>
      </button>
    </div>

    <!-- Household detail -->
    <div v-else class="space-y-6">
      <!-- Back button -->
      <button
        @click="selectedHousehold = null"
        class="flex items-center gap-2 text-charcoal-500 hover:text-charcoal-700 transition-colors"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Back to households
      </button>

      <!-- Household header -->
      <div class="card p-4">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="font-hand text-xl text-charcoal-700">{{ selectedHousehold.name }}</h2>
            <p class="text-sm text-charcoal-400">
              Created {{ formatDate(selectedHousehold.created_at) }}
              <span v-if="isOwner" class="text-sage-600 font-medium ml-2">You're the owner</span>
            </p>
          </div>
          <button
            v-if="isOwner"
            @click="showInviteModal = true"
            class="btn-primary text-sm px-4 py-2"
          >
            + Invite Member
          </button>
        </div>
      </div>

      <!-- Members -->
      <div class="card p-4">
        <h3 class="font-hand text-lg text-charcoal-700 mb-3 flex items-center gap-2">
          <img src="https://img.icons8.com/doodle/48/user-group-man-man.png" alt="" class="w-5 h-5">
          Members ({{ members.length }})
        </h3>

        <div class="space-y-2">
          <div
            v-for="member in members"
            :key="member.user_id"
            class="flex items-center justify-between p-3 bg-cream-50 rounded-xl"
          >
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-sage-100 rounded-full flex items-center justify-center">
                <span class="text-sage-600 font-semibold">
                  {{ (member.display_name || member.email).charAt(0).toUpperCase() }}
                </span>
              </div>
              <div>
                <p class="font-medium text-charcoal-700">
                  {{ member.display_name || member.email.split('@')[0] }}
                  <span v-if="member.is_self" class="text-charcoal-400 font-normal">(you)</span>
                </p>
                <p class="text-xs text-charcoal-400">
                  {{ member.role === 'owner' ? 'Owner' : 'Member' }}
                </p>
              </div>
            </div>

            <button
              v-if="(isOwner && !member.is_self && member.role !== 'owner') || (member.is_self && member.role !== 'owner')"
              @click="removeMember(member.user_id)"
              class="text-xs text-terracotta-600 hover:underline"
            >
              {{ member.is_self ? 'Leave' : 'Remove' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Pending Invitations -->
      <div v-if="pendingInvitations.length > 0" class="card p-4">
        <h3 class="font-hand text-lg text-charcoal-700 mb-3 flex items-center gap-2">
          <img src="https://img.icons8.com/doodle/48/secured-letter.png" alt="" class="w-5 h-5">
          Pending Invitations
        </h3>

        <div class="space-y-2">
          <div
            v-for="invite in pendingInvitations"
            :key="invite.id"
            class="flex items-center justify-between p-3 bg-sunny-50 rounded-xl"
          >
            <div>
              <p class="font-medium text-charcoal-700">{{ invite.email }}</p>
              <p class="text-xs text-charcoal-400">
                Expires {{ formatDate(invite.expires_at) }}
              </p>
            </div>
            <button
              v-if="isOwner"
              @click="revokeInvitation(invite.id)"
              class="text-xs text-terracotta-600 hover:underline"
            >
              Cancel
            </button>
          </div>
        </div>
      </div>

      <!-- Shared Plants -->
      <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-hand text-lg text-charcoal-700 flex items-center gap-2">
            <img src="https://img.icons8.com/doodle/48/potted-plant.png" alt="" class="w-5 h-5">
            Shared Plants ({{ sharedPlants.length }})
          </h3>
          <button
            v-if="isOwner && unsahredPlants.length > 0"
            @click="showSharePlantsModal = true"
            class="text-sm text-sage-600 hover:underline"
          >
            + Share Plants
          </button>
        </div>

        <div v-if="sharedPlants.length === 0" class="text-center py-6 text-charcoal-400">
          <p>No plants shared yet</p>
          <button
            v-if="isOwner && myOwnedPlants.length > 0"
            @click="showSharePlantsModal = true"
            class="btn-primary text-sm mt-3"
          >
            Share Your Plants
          </button>
        </div>

        <div v-else class="grid grid-cols-2 gap-3">
          <div
            v-for="plant in sharedPlants"
            :key="plant.id"
            class="relative group"
          >
            <router-link
              :to="`/plants/${plant.id}`"
              class="block p-3 bg-cream-50 rounded-xl hover:bg-cream-100 transition-colors"
            >
              <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-sage-100 rounded-xl overflow-hidden flex-shrink-0">
                  <img
                    v-if="plant.thumbnail"
                    :src="`/uploads/plants/${plant.thumbnail}`"
                    :alt="plant.name"
                    class="w-full h-full object-cover"
                  >
                  <div v-else class="w-full h-full flex items-center justify-center">
                    <img src="https://img.icons8.com/doodle/48/potted-plant.png" alt="" class="w-6 h-6 opacity-50">
                  </div>
                </div>
                <div class="min-w-0">
                  <p class="font-medium text-charcoal-700 truncate">{{ plant.name }}</p>
                  <p class="text-xs text-charcoal-400 truncate">{{ plant.species || 'Unknown species' }}</p>
                </div>
              </div>
            </router-link>

            <button
              v-if="isOwner"
              @click.prevent="unsharePlant(plant.id)"
              class="absolute -top-2 -right-2 w-6 h-6 bg-terracotta-100 text-terracotta-600 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity hover:bg-terracotta-200"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Delete household -->
      <div v-if="isOwner" class="pt-4 border-t border-cream-200">
        <button
          @click="deleteHousehold"
          class="text-sm text-terracotta-600 hover:underline"
        >
          Delete Household
        </button>
      </div>
    </div>

    <!-- Create Household Modal -->
    <div v-if="showCreateModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="card p-6 w-full max-w-md">
        <h2 class="font-hand text-xl text-charcoal-700 mb-4">Create Household</h2>

        <div class="mb-4">
          <label class="form-label">Household Name</label>
          <input
            v-model="newHouseholdName"
            type="text"
            class="input"
            placeholder="e.g., Our Family, Apartment 4B"
          >
        </div>

        <div class="flex gap-2">
          <button
            @click="createHousehold"
            :disabled="!newHouseholdName.trim() || creating"
            class="btn-primary flex-1"
          >
            <span v-if="creating">Creating...</span>
            <span v-else>Create</span>
          </button>
          <button
            @click="showCreateModal = false; newHouseholdName = ''"
            class="btn-secondary"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>

    <!-- Invite Member Modal -->
    <div v-if="showInviteModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="card p-6 w-full max-w-md">
        <h2 class="font-hand text-xl text-charcoal-700 mb-4">Invite Member</h2>

        <div class="space-y-4">
          <div>
            <label class="form-label">Email Address</label>
            <input
              v-model="inviteEmail"
              type="email"
              class="input"
              placeholder="friend@example.com"
            >
          </div>

          <div>
            <label class="form-label">Plants to Share</label>
            <div class="space-y-2">
              <label class="flex items-center gap-2 p-3 bg-cream-50 rounded-xl cursor-pointer">
                <input v-model="shareAllPlants" type="radio" :value="true" class="text-sage-600">
                <span class="text-charcoal-700">Share all my plants</span>
              </label>
              <label class="flex items-center gap-2 p-3 bg-cream-50 rounded-xl cursor-pointer">
                <input v-model="shareAllPlants" type="radio" :value="false" class="text-sage-600">
                <span class="text-charcoal-700">Choose specific plants</span>
              </label>
            </div>
          </div>

          <div v-if="!shareAllPlants" class="max-h-48 overflow-y-auto space-y-2 p-2 bg-cream-50 rounded-xl">
            <label
              v-for="plant in myOwnedPlants"
              :key="plant.id"
              class="flex items-center gap-2 p-2 hover:bg-white rounded-lg cursor-pointer"
            >
              <input
                v-model="selectedPlantIds"
                type="checkbox"
                :value="plant.id"
                class="text-sage-600"
              >
              <span class="text-sm text-charcoal-700">{{ plant.name }}</span>
            </label>
          </div>
        </div>

        <div class="flex gap-2 mt-6">
          <button
            @click="sendInvitation"
            :disabled="!inviteEmail.trim() || inviting"
            class="btn-primary flex-1"
          >
            <span v-if="inviting">Sending...</span>
            <span v-else>Send Invitation</span>
          </button>
          <button
            @click="showInviteModal = false; inviteEmail = ''; selectedPlantIds = []"
            class="btn-secondary"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>

    <!-- Share Plants Modal -->
    <div v-if="showSharePlantsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="card p-6 w-full max-w-md">
        <h2 class="font-hand text-xl text-charcoal-700 mb-4">Share Plants</h2>

        <div v-if="unsahredPlants.length === 0" class="text-center py-6 text-charcoal-400">
          All your plants are already shared!
        </div>

        <div v-else class="max-h-64 overflow-y-auto space-y-2 mb-4">
          <label
            v-for="plant in unsahredPlants"
            :key="plant.id"
            class="flex items-center gap-3 p-3 hover:bg-cream-50 rounded-xl cursor-pointer"
          >
            <input
              v-model="plantsToShare"
              type="checkbox"
              :value="plant.id"
              class="text-sage-600"
            >
            <div class="w-10 h-10 bg-sage-100 rounded-lg overflow-hidden flex-shrink-0">
              <img
                v-if="plant.thumbnail"
                :src="`/uploads/plants/${plant.thumbnail}`"
                :alt="plant.name"
                class="w-full h-full object-cover"
              >
            </div>
            <div class="min-w-0">
              <p class="font-medium text-charcoal-700">{{ plant.name }}</p>
              <p class="text-xs text-charcoal-400">{{ plant.species || 'Unknown species' }}</p>
            </div>
          </label>
        </div>

        <div class="flex gap-2">
          <button
            @click="sharePlants"
            :disabled="plantsToShare.length === 0 || sharing"
            class="btn-primary flex-1"
          >
            <span v-if="sharing">Sharing...</span>
            <span v-else>Share {{ plantsToShare.length }} Plant{{ plantsToShare.length !== 1 ? 's' : '' }}</span>
          </button>
          <button
            @click="showSharePlantsModal = false; plantsToShare = []"
            class="btn-secondary"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

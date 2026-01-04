<script setup>
import { ref, onMounted, computed } from 'vue'
import { useApi } from '@/composables/useApi'

const api = useApi()

const pots = ref([])
const loading = ref(true)
const showAddModal = ref(false)
const showEditModal = ref(false)
const editingPot = ref(null)
const saving = ref(false)
const deleting = ref(false)

// Form data
const form = ref({
  name: '',
  size: 'medium',
  diameter_inches: '',
  has_drainage: true,
  material: '',
  color: '',
  notes: ''
})

const sizeOptions = [
  { value: 'tiny', label: 'Tiny (2-3")' },
  { value: 'small', label: 'Small (4-5")' },
  { value: 'medium', label: 'Medium (6-8")' },
  { value: 'large', label: 'Large (10-12")' },
  { value: 'xlarge', label: 'X-Large (14"+)' }
]

const materialOptions = [
  { value: 'ceramic', label: 'Ceramic' },
  { value: 'terracotta', label: 'Terracotta' },
  { value: 'plastic', label: 'Plastic' },
  { value: 'concrete', label: 'Concrete' },
  { value: 'metal', label: 'Metal' },
  { value: 'wood', label: 'Wood' },
  { value: 'glass', label: 'Glass' },
  { value: 'other', label: 'Other' }
]

const availablePots = computed(() => pots.value.filter(p => p.available))
const inUsePots = computed(() => pots.value.filter(p => !p.available))

onMounted(async () => {
  await loadPots()
})

async function loadPots() {
  loading.value = true
  try {
    const response = await api.get('/pots')
    pots.value = response.pots || []
  } catch (e) {
    window.$toast?.error('Failed to load pots')
  } finally {
    loading.value = false
  }
}

function resetForm() {
  form.value = {
    name: '',
    size: 'medium',
    diameter_inches: '',
    has_drainage: true,
    material: '',
    color: '',
    notes: ''
  }
}

function openAddModal() {
  resetForm()
  showAddModal.value = true
}

function openEditModal(pot) {
  editingPot.value = pot
  form.value = {
    name: pot.name || '',
    size: pot.size || 'medium',
    diameter_inches: pot.diameter_inches || '',
    has_drainage: !!pot.has_drainage,
    material: pot.material || '',
    color: pot.color || '',
    notes: pot.notes || ''
  }
  showEditModal.value = true
}

async function savePot() {
  saving.value = true
  try {
    const data = {
      ...form.value,
      diameter_inches: form.value.diameter_inches ? parseFloat(form.value.diameter_inches) : null,
      has_drainage: form.value.has_drainage ? 1 : 0
    }

    if (editingPot.value) {
      await api.put(`/pots/${editingPot.value.id}`, data)
      window.$toast?.success('Pot updated')
    } else {
      await api.post('/pots', data)
      window.$toast?.success('Pot added')
    }

    showAddModal.value = false
    showEditModal.value = false
    editingPot.value = null
    await loadPots()
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to save pot')
  } finally {
    saving.value = false
  }
}

async function deletePot(pot) {
  if (!confirm(`Delete ${pot.name || 'this pot'}?`)) return

  deleting.value = true
  try {
    await api.delete(`/pots/${pot.id}`)
    window.$toast?.success('Pot deleted')
    showEditModal.value = false
    await loadPots()
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to delete pot')
  } finally {
    deleting.value = false
  }
}

async function markAvailable(pot) {
  try {
    await api.post(`/pots/${pot.id}/assign`, { plant_id: null })
    window.$toast?.success('Pot marked as available')
    await loadPots()
  } catch (e) {
    window.$toast?.error('Failed to update pot')
  }
}

function getSizeLabel(size) {
  return sizeOptions.find(s => s.value === size)?.label || size
}

function getMaterialLabel(material) {
  return materialOptions.find(m => m.value === material)?.label || material
}
</script>

<template>
  <div class="page-container">
    <!-- Header -->
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Pot Inventory</h1>
        <p class="text-sm text-gray-500">Track your available pots for repotting</p>
      </div>
      <button @click="openAddModal" class="btn-primary px-3 py-2">
        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add Pot
      </button>
    </header>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Empty state -->
      <div v-if="pots.length === 0" class="text-center py-12">
        <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
          <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
          </svg>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No pots yet</h3>
        <p class="text-gray-500 mb-4">Track your available pots for when you need to repot</p>
        <button @click="openAddModal" class="btn-primary">Add Your First Pot</button>
      </div>

      <!-- Available Pots -->
      <div v-if="availablePots.length > 0" class="mb-8">
        <h2 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
          <span class="w-2 h-2 bg-green-500 rounded-full"></span>
          Available ({{ availablePots.length }})
        </h2>
        <div class="grid grid-cols-2 gap-3">
          <div
            v-for="pot in availablePots"
            :key="pot.id"
            @click="openEditModal(pot)"
            class="card p-3 cursor-pointer hover:shadow-md transition-shadow"
          >
            <div class="aspect-square rounded-lg overflow-hidden bg-gray-100 mb-2">
              <img
                v-if="pot.image"
                :src="`/uploads/plants/${pot.image_thumbnail || pot.image}`"
                class="w-full h-full object-cover"
              >
              <div v-else class="w-full h-full flex items-center justify-center">
                <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                </svg>
              </div>
            </div>
            <p class="font-medium text-gray-900 text-sm truncate">{{ pot.name || getSizeLabel(pot.size) }}</p>
            <div class="flex items-center gap-2 mt-1">
              <span class="text-xs text-gray-500">{{ getSizeLabel(pot.size) }}</span>
              <span v-if="pot.material" class="text-xs text-gray-400">{{ getMaterialLabel(pot.material) }}</span>
            </div>
            <div class="flex items-center gap-1 mt-1">
              <span
                v-if="pot.has_drainage"
                class="text-xs bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded"
              >Drainage</span>
              <span
                v-if="pot.color"
                class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded"
              >{{ pot.color }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- In Use Pots -->
      <div v-if="inUsePots.length > 0">
        <h2 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
          <span class="w-2 h-2 bg-gray-400 rounded-full"></span>
          In Use ({{ inUsePots.length }})
        </h2>
        <div class="space-y-2">
          <div
            v-for="pot in inUsePots"
            :key="pot.id"
            class="card p-3 flex items-center gap-3"
          >
            <div class="w-12 h-12 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0">
              <img
                v-if="pot.image"
                :src="`/uploads/plants/${pot.image_thumbnail || pot.image}`"
                class="w-full h-full object-cover"
              >
              <div v-else class="w-full h-full flex items-center justify-center">
                <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                </svg>
              </div>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-medium text-gray-900 text-sm truncate">{{ pot.name || getSizeLabel(pot.size) }}</p>
              <p class="text-xs text-gray-500">
                Used by: <span class="text-plant-600">{{ pot.plant_name }}</span>
              </p>
            </div>
            <button
              @click.stop="markAvailable(pot)"
              class="text-xs text-plant-600 hover:underline"
            >
              Mark Available
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Add/Edit Modal -->
    <div v-if="showAddModal || showEditModal" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
      <div class="bg-white rounded-t-3xl w-full max-w-lg p-6 safe-bottom max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold mb-4">{{ editingPot ? 'Edit Pot' : 'Add Pot' }}</h3>

        <div class="space-y-4">
          <!-- Name -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name (optional)</label>
            <input v-model="form.name" type="text" class="input" placeholder="e.g., White ceramic planter">
          </div>

          <!-- Size -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Size *</label>
            <select v-model="form.size" class="input">
              <option v-for="opt in sizeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>

          <!-- Diameter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Exact Diameter (inches)</label>
            <input v-model="form.diameter_inches" type="number" step="0.5" class="input" placeholder="e.g., 6">
          </div>

          <!-- Material -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Material</label>
            <select v-model="form.material" class="input">
              <option value="">Not specified</option>
              <option v-for="opt in materialOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>

          <!-- Color -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Color</label>
            <input v-model="form.color" type="text" class="input" placeholder="e.g., White, Terra cotta">
          </div>

          <!-- Drainage -->
          <div class="flex items-center gap-2">
            <input type="checkbox" v-model="form.has_drainage" id="drainage" class="w-4 h-4 rounded border-gray-300 text-plant-600 focus:ring-plant-500">
            <label for="drainage" class="text-sm text-gray-700">Has drainage hole(s)</label>
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea v-model="form.notes" class="input" rows="2" placeholder="Any additional notes..."></textarea>
          </div>
        </div>

        <div class="flex gap-3 mt-6">
          <button
            @click="showAddModal = false; showEditModal = false; editingPot = null"
            class="btn-secondary flex-1"
          >
            Cancel
          </button>
          <button @click="savePot" :disabled="saving || !form.size" class="btn-primary flex-1">
            <span v-if="saving" class="flex items-center justify-center gap-2">
              <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Saving...
            </span>
            <span v-else>{{ editingPot ? 'Save Changes' : 'Add Pot' }}</span>
          </button>
        </div>

        <!-- Delete button for editing -->
        <button
          v-if="editingPot"
          @click="deletePot(editingPot)"
          :disabled="deleting"
          class="w-full text-red-600 text-sm font-medium py-3 mt-3"
        >
          {{ deleting ? 'Deleting...' : 'Delete Pot' }}
        </button>
      </div>
    </div>
  </div>
</template>

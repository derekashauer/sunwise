<script setup>
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { usePlantsStore } from '@/stores/plants'

const api = useApi()
const plantsStore = usePlantsStore()

const items = ref([])
const loading = ref(true)
const showAddModal = ref(false)
const showPurchased = ref(false)

// Add/Edit form
const formItem = ref('')
const formPlantId = ref(null)
const formQuantity = ref(1)
const formNotes = ref('')
const editingId = ref(null)
const saving = ref(false)

const unpurchasedItems = computed(() => items.value.filter(i => !i.purchased))
const purchasedItems = computed(() => items.value.filter(i => i.purchased))

// Group items by plant
const groupedItems = computed(() => {
  const groups = {}
  const noPlant = []

  unpurchasedItems.value.forEach(item => {
    if (item.plant_id) {
      const key = item.plant_id
      if (!groups[key]) {
        groups[key] = {
          plant_name: item.plant_name,
          plant_species: item.plant_species,
          items: []
        }
      }
      groups[key].items.push(item)
    } else {
      noPlant.push(item)
    }
  })

  return { byPlant: groups, general: noPlant }
})

onMounted(async () => {
  await Promise.all([
    loadItems(),
    plantsStore.fetchPlants()
  ])
})

async function loadItems() {
  loading.value = true
  try {
    const response = await api.get('/shopping-list?purchased=1')
    items.value = response.items
  } catch (e) {
    window.$toast?.error('Failed to load shopping list')
  } finally {
    loading.value = false
  }
}

function openAddModal(plantId = null) {
  formItem.value = ''
  formPlantId.value = plantId
  formQuantity.value = 1
  formNotes.value = ''
  editingId.value = null
  showAddModal.value = true
}

function openEditModal(item) {
  formItem.value = item.item
  formPlantId.value = item.plant_id
  formQuantity.value = item.quantity
  formNotes.value = item.notes || ''
  editingId.value = item.id
  showAddModal.value = true
}

async function saveItem() {
  if (!formItem.value.trim()) {
    window.$toast?.error('Please enter an item name')
    return
  }

  saving.value = true
  try {
    const data = {
      item: formItem.value.trim(),
      plant_id: formPlantId.value,
      quantity: formQuantity.value,
      notes: formNotes.value.trim() || null
    }

    if (editingId.value) {
      const response = await api.put(`/shopping-list/${editingId.value}`, data)
      const index = items.value.findIndex(i => i.id === editingId.value)
      if (index !== -1) {
        items.value[index] = response.item
      }
      window.$toast?.success('Item updated')
    } else {
      const response = await api.post('/shopping-list', data)
      items.value.unshift(response.item)
      window.$toast?.success('Item added to list')
    }

    showAddModal.value = false
  } catch (e) {
    window.$toast?.error(e.message || 'Failed to save item')
  } finally {
    saving.value = false
  }
}

async function togglePurchased(item) {
  try {
    const response = await api.post(`/shopping-list/${item.id}/toggle`)
    const index = items.value.findIndex(i => i.id === item.id)
    if (index !== -1) {
      items.value[index] = response.item
    }
  } catch (e) {
    window.$toast?.error('Failed to update item')
  }
}

async function deleteItem(item) {
  try {
    await api.delete(`/shopping-list/${item.id}`)
    items.value = items.value.filter(i => i.id !== item.id)
    window.$toast?.success('Item removed')
  } catch (e) {
    window.$toast?.error('Failed to delete item')
  }
}

async function clearPurchased() {
  try {
    await api.delete('/shopping-list/purchased')
    items.value = items.value.filter(i => !i.purchased)
    window.$toast?.success('Purchased items cleared')
  } catch (e) {
    window.$toast?.error('Failed to clear items')
  }
}
</script>

<template>
  <div class="page-container">
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="page-title mb-0">Shopping List</h1>
        <p class="text-sm text-gray-500">{{ unpurchasedItems.length }} item{{ unpurchasedItems.length !== 1 ? 's' : '' }} to get</p>
      </div>
      <button @click="openAddModal()" class="btn-primary">
        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add Item
      </button>
    </header>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Empty state -->
      <div v-if="items.length === 0" class="card p-8 text-center">
        <div class="w-16 h-16 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
          <svg class="w-8 h-8 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </div>
        <h3 class="font-semibold text-gray-900 mb-2">Your shopping list is empty</h3>
        <p class="text-gray-500 text-sm mb-4">Add supplies you need for your plants</p>
        <button @click="openAddModal()" class="btn-primary">
          Add First Item
        </button>
      </div>

      <!-- Items list -->
      <div v-else class="space-y-6">
        <!-- General items (no plant) -->
        <div v-if="groupedItems.general.length > 0" class="card">
          <div class="p-3 border-b bg-gray-50 rounded-t-xl">
            <h3 class="font-medium text-gray-700">General Supplies</h3>
          </div>
          <div class="divide-y">
            <div
              v-for="item in groupedItems.general"
              :key="item.id"
              class="p-3 flex items-center gap-3"
            >
              <button
                @click="togglePurchased(item)"
                class="w-6 h-6 rounded-full border-2 flex-shrink-0 flex items-center justify-center transition-all"
                :class="item.purchased
                  ? 'bg-plant-500 border-plant-500'
                  : 'border-gray-300 hover:border-plant-500'"
              >
                <svg v-if="item.purchased" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-900" :class="{ 'line-through text-gray-400': item.purchased }">
                  {{ item.item }}
                  <span v-if="item.quantity > 1" class="text-gray-500 font-normal">(x{{ item.quantity }})</span>
                </p>
                <p v-if="item.notes" class="text-sm text-gray-500 truncate">{{ item.notes }}</p>
              </div>
              <button @click="openEditModal(item)" class="p-2 text-gray-400 hover:text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
              </button>
              <button @click="deleteItem(item)" class="p-2 text-gray-400 hover:text-red-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Items grouped by plant -->
        <div
          v-for="(group, plantId) in groupedItems.byPlant"
          :key="plantId"
          class="card"
        >
          <div class="p-3 border-b bg-plant-50 rounded-t-xl flex items-center justify-between">
            <div>
              <h3 class="font-medium text-plant-800">{{ group.plant_name }}</h3>
              <p v-if="group.plant_species" class="text-xs text-plant-600">{{ group.plant_species }}</p>
            </div>
            <router-link :to="`/plants/${plantId}`" class="text-xs text-plant-600 hover:text-plant-700">
              View Plant
            </router-link>
          </div>
          <div class="divide-y">
            <div
              v-for="item in group.items"
              :key="item.id"
              class="p-3 flex items-center gap-3"
            >
              <button
                @click="togglePurchased(item)"
                class="w-6 h-6 rounded-full border-2 flex-shrink-0 flex items-center justify-center transition-all"
                :class="item.purchased
                  ? 'bg-plant-500 border-plant-500'
                  : 'border-gray-300 hover:border-plant-500'"
              >
                <svg v-if="item.purchased" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-900" :class="{ 'line-through text-gray-400': item.purchased }">
                  {{ item.item }}
                  <span v-if="item.quantity > 1" class="text-gray-500 font-normal">(x{{ item.quantity }})</span>
                </p>
                <p v-if="item.notes" class="text-sm text-gray-500 truncate">{{ item.notes }}</p>
              </div>
              <button @click="openEditModal(item)" class="p-2 text-gray-400 hover:text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
              </button>
              <button @click="deleteItem(item)" class="p-2 text-gray-400 hover:text-red-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Purchased items section -->
        <div v-if="purchasedItems.length > 0" class="mt-8">
          <button
            @click="showPurchased = !showPurchased"
            class="flex items-center gap-2 text-gray-500 hover:text-gray-700 mb-3"
          >
            <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': showPurchased }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
            <span class="text-sm font-medium">Purchased ({{ purchasedItems.length }})</span>
          </button>

          <div v-if="showPurchased" class="card">
            <div class="divide-y">
              <div
                v-for="item in purchasedItems"
                :key="item.id"
                class="p-3 flex items-center gap-3 opacity-60"
              >
                <button
                  @click="togglePurchased(item)"
                  class="w-6 h-6 rounded-full bg-plant-500 border-2 border-plant-500 flex-shrink-0 flex items-center justify-center"
                >
                  <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                </button>
                <div class="flex-1 min-w-0">
                  <p class="font-medium text-gray-400 line-through">
                    {{ item.item }}
                    <span v-if="item.quantity > 1">(x{{ item.quantity }})</span>
                  </p>
                  <p v-if="item.plant_name" class="text-xs text-gray-400">For: {{ item.plant_name }}</p>
                </div>
                <button @click="deleteItem(item)" class="p-2 text-gray-400 hover:text-red-500">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </div>
            <div class="p-3 border-t">
              <button
                @click="clearPurchased"
                class="text-sm text-red-500 hover:text-red-600 font-medium"
              >
                Clear All Purchased
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Add/Edit Modal -->
    <div v-if="showAddModal" class="fixed inset-0 bg-black/50 flex items-end justify-center z-50">
      <div class="bg-white rounded-t-3xl w-full max-w-lg p-6 safe-bottom">
        <h3 class="text-lg font-semibold mb-4">{{ editingId ? 'Edit Item' : 'Add to Shopping List' }}</h3>

        <div class="space-y-4">
          <div>
            <label for="item" class="block text-sm font-medium text-gray-700 mb-1">Item *</label>
            <input
              id="item"
              v-model="formItem"
              type="text"
              class="input"
              placeholder="e.g., Pebble tray, Fertilizer, New pot..."
            >
          </div>

          <div>
            <label for="plant" class="block text-sm font-medium text-gray-700 mb-1">For Plant (optional)</label>
            <select id="plant" v-model="formPlantId" class="input">
              <option :value="null">General / No specific plant</option>
              <option v-for="plant in plantsStore.plants" :key="plant.id" :value="plant.id">
                {{ plant.name }} ({{ plant.species || 'Unknown species' }})
              </option>
            </select>
          </div>

          <div class="flex gap-4">
            <div class="w-24">
              <label for="quantity" class="block text-sm font-medium text-gray-700 mb-1">Qty</label>
              <input
                id="quantity"
                v-model="formQuantity"
                type="number"
                min="1"
                class="input"
              >
            </div>
            <div class="flex-1">
              <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
              <input
                id="notes"
                v-model="formNotes"
                type="text"
                class="input"
                placeholder="Size, brand, etc..."
              >
            </div>
          </div>
        </div>

        <div class="flex gap-3 mt-6">
          <button @click="showAddModal = false" class="btn-secondary flex-1">Cancel</button>
          <button @click="saveItem" :disabled="saving" class="btn-primary flex-1">
            <span v-if="saving" class="flex items-center justify-center gap-2">
              <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
              Saving...
            </span>
            <span v-else>{{ editingId ? 'Update' : 'Add Item' }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

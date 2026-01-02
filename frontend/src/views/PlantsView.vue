<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { usePlantsStore } from '@/stores/plants'
import PlantCard from '@/components/plants/PlantCard.vue'

const router = useRouter()
const plants = usePlantsStore()

onMounted(() => {
  plants.fetchPlants()
})
</script>

<template>
  <div class="page-container">
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="page-title mb-0">My Plants</h1>
        <p class="text-gray-500">{{ plants.plantsCount }} plant{{ plants.plantsCount !== 1 ? 's' : '' }}</p>
      </div>
      <button @click="router.push('/plants/add')" class="btn-primary">
        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add
      </button>
    </header>

    <!-- Loading state -->
    <div v-if="plants.loading" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="plants.plants.length === 0" class="text-center py-12">
      <div class="w-20 h-20 mx-auto mb-4 bg-plant-100 rounded-full flex items-center justify-center">
        <svg class="w-10 h-10 text-plant-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
        </svg>
      </div>
      <h2 class="text-lg font-semibold text-gray-900 mb-2">No plants yet</h2>
      <p class="text-gray-500 mb-6">Add your first plant to get started</p>
      <button @click="router.push('/plants/add')" class="btn-primary">
        Add Your First Plant
      </button>
    </div>

    <!-- Plants grid -->
    <div v-else class="grid grid-cols-2 gap-4">
      <PlantCard
        v-for="plant in plants.plants"
        :key="plant.id"
        :plant="plant"
        @click="router.push(`/plants/${plant.id}`)"
      />
    </div>
  </div>
</template>

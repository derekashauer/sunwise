<script setup>
defineProps({
  plant: { type: Object, required: true }
})

const healthColors = {
  thriving: 'bg-green-100 text-green-700',
  healthy: 'bg-plant-100 text-plant-700',
  struggling: 'bg-yellow-100 text-yellow-700',
  critical: 'bg-red-100 text-red-700',
  unknown: 'bg-gray-100 text-gray-500'
}
</script>

<template>
  <div class="card overflow-hidden cursor-pointer hover:shadow-md transition-shadow active:scale-98">
    <!-- Plant image -->
    <div class="aspect-square bg-gray-100 relative">
      <img
        v-if="plant.thumbnail"
        :src="`/uploads/plants/${plant.thumbnail}`"
        :alt="plant.name"
        class="w-full h-full object-cover"
      >
      <div v-else class="w-full h-full flex items-center justify-center">
        <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
        </svg>
      </div>

      <!-- Health badge -->
      <span
        v-if="plant.health_status && plant.health_status !== 'unknown'"
        class="absolute top-2 right-2 px-2 py-0.5 text-xs font-medium rounded-full capitalize"
        :class="healthColors[plant.health_status]"
      >
        {{ plant.health_status }}
      </span>
    </div>

    <!-- Plant info -->
    <div class="p-3">
      <h3 class="font-semibold text-gray-900 truncate">{{ plant.name }}</h3>
      <p v-if="plant.species" class="text-sm text-gray-500 truncate">{{ plant.species }}</p>
      <p v-if="plant.location" class="text-xs text-gray-400 mt-1 truncate">{{ plant.location }}</p>
    </div>
  </div>
</template>

<style scoped>
.active\:scale-98:active {
  transform: scale(0.98);
}
</style>

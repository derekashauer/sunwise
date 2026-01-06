<script setup>
defineProps({
  plant: { type: Object, required: true }
})

const healthColors = {
  thriving: 'bg-sage-100 text-sage-700',
  healthy: 'bg-sage-50 text-sage-600',
  struggling: 'bg-sunny-100 text-sunny-700',
  critical: 'bg-terracotta-100 text-terracotta-700',
  unknown: 'bg-cream-200 text-charcoal-400'
}
</script>

<template>
  <div class="plant-card overflow-hidden cursor-pointer">
    <!-- Plant image -->
    <div class="aspect-square bg-cream-200 relative">
      <img
        v-if="plant.thumbnail"
        :src="`/uploads/plants/${plant.thumbnail}`"
        :alt="plant.name"
        class="w-full h-full object-cover"
      >
      <div v-else class="w-full h-full flex items-center justify-center">
        <img
          src="https://img.icons8.com/doodle/96/potted-plant--v1.png"
          alt="no photo"
          class="w-12 h-12 opacity-40"
        >
      </div>

      <!-- Health badge -->
      <span
        v-if="plant.health_status && plant.health_status !== 'unknown'"
        class="absolute top-2 right-2 px-2 py-0.5 text-xs font-medium rounded-full capitalize shadow-sm"
        :class="healthColors[plant.health_status]"
      >
        {{ plant.health_status }}
      </span>

      <!-- Propagation badge -->
      <span
        v-if="plant.is_propagation"
        class="absolute top-2 left-2 px-2 py-0.5 text-xs font-medium rounded-full bg-purple-100 text-purple-700 shadow-sm"
      >
        Prop
      </span>
    </div>

    <!-- Plant info -->
    <div class="p-3 bg-white">
      <h3 class="font-semibold text-charcoal-700 truncate">{{ plant.name }}</h3>
      <p v-if="plant.species" class="text-sm text-charcoal-400 truncate">{{ plant.species }}</p>
      <p v-if="plant.location_name" class="text-xs text-charcoal-300 mt-1 truncate flex items-center gap-1">
        <img
          src="https://img.icons8.com/doodle/48/place-marker.png"
          alt=""
          class="w-3 h-3"
        >
        {{ plant.location_name }}
      </p>
    </div>
  </div>
</template>

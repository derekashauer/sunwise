<script setup>
import { computed } from 'vue'
import { parseUtc, parseLocalDate, daysFromToday } from '@/utils/dates'

const props = defineProps({
  plant: { type: Object, required: true }
})

const healthColors = {
  thriving: 'bg-sage-100 text-sage-800',
  healthy: 'bg-sage-50 text-sage-800',
  struggling: 'bg-sunny-100 text-sunny-900',
  critical: 'bg-terracotta-100 text-terracotta-800',
  unknown: 'bg-cream-200 text-charcoal-500'
}

// "Watered 4 days ago" from the last watering in the care log.
const wateredText = computed(() => {
  const last = parseUtc(props.plant.last_watered_at)
  if (!last) return 'Watering never logged'
  const days = -daysFromToday(last)
  if (days <= 0) return 'Watered today'
  if (days === 1) return 'Watered yesterday'
  return `Watered ${days} days ago`
})

// "next in 3 days" from the pending water task.
const nextWater = computed(() => {
  const due = parseLocalDate(props.plant.next_water_due)
  if (!due) return null
  const days = daysFromToday(due)
  if (days < 0) return { text: `water ${Math.abs(days)} day${days === -1 ? '' : 's'} late`, late: true }
  if (days === 0) return { text: 'water today', late: false }
  if (days === 1) return { text: 'next tomorrow', late: false }
  return { text: `next in ${days} days`, late: false }
})

const showCareStatus = computed(() =>
  // Water propagations don't get watered.
  !(Number(props.plant.is_propagation) === 1 && props.plant.soil_type === 'water')
)
</script>

<template>
  <div class="plant-card overflow-hidden cursor-pointer">
    <!-- Plant image -->
    <div class="aspect-square bg-cream-200 relative">
      <img
        v-if="plant.thumbnail"
        :src="`/uploads/plants/${plant.thumbnail}`"
        :alt="plant.name"
        loading="lazy"
        class="w-full h-full object-cover"
      >
      <div v-else class="w-full h-full flex items-center justify-center">
        <img
          src="https://img.icons8.com/doodle/96/potted-plant--v1.png"
          alt=""
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
        class="absolute top-2 left-2 px-2 py-0.5 text-xs font-medium rounded-full bg-purple-100 text-purple-800 shadow-sm"
      >
        Prop
      </span>
    </div>

    <!-- Plant info -->
    <div class="p-3 bg-white">
      <h3 class="font-semibold text-charcoal-700 truncate">{{ plant.name }}</h3>
      <p v-if="plant.species" class="text-sm text-charcoal-500 truncate">{{ plant.species }}</p>
      <p v-if="showCareStatus" class="text-xs text-charcoal-600 mt-1 flex items-center gap-1 flex-wrap">
        <img src="https://img.icons8.com/doodle/48/watering-can.png" alt="" class="w-3.5 h-3.5">
        <span>{{ wateredText }}</span>
        <template v-if="nextWater">
          <span aria-hidden="true">·</span>
          <span :class="nextWater.late ? 'text-red-800 font-semibold' : ''">{{ nextWater.text }}</span>
        </template>
      </p>
      <p v-if="plant.location_name" class="text-xs text-charcoal-500 mt-1 truncate flex items-center gap-1">
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

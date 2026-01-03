<script setup>
import { ref, computed } from 'vue'
import { useTasksStore } from '@/stores/tasks'

const props = defineProps({
  task: { type: Object, required: true },
  plant: { type: Object, default: null },
  completed: { type: Boolean, default: false },
  showRecommendations: { type: Boolean, default: true }
})

const tasks = useTasksStore()
const loading = ref(false)
const expanded = ref(false)

const taskIcons = {
  water: '💧',
  fertilize: '🌱',
  trim: '✂️',
  repot: '🪴',
  rotate: '🔄',
  mist: '💨',
  check: '👀'
}

// Care recommendations based on task type and plant properties
const careRecommendations = computed(() => {
  const taskType = props.task.task_type
  const potSize = props.plant?.pot_size || props.task.pot_size || 'medium'
  const species = props.plant?.species || props.task.species || ''
  const soilType = props.plant?.soil_type || props.task.soil_type || 'standard'

  const recommendations = {
    water: getWateringTips(potSize, species, soilType),
    fertilize: getFertilizingTips(potSize, species),
    trim: getTrimmingTips(species),
    repot: getRepottingTips(potSize, species),
    rotate: getRotatingTips(),
    mist: getMistingTips(species),
    check: getCheckTips()
  }

  return recommendations[taskType] || null
})

function getWateringTips(potSize, species, soilType) {
  const amounts = {
    small: '~100-200ml (half cup)',
    medium: '~300-500ml (1-2 cups)',
    large: '~500-750ml (2-3 cups)',
    xlarge: '~1-1.5 liters'
  }

  let tips = [`<strong>Amount:</strong> ${amounts[potSize] || amounts.medium}`]

  // Species-specific tips
  const speciesLower = species.toLowerCase()
  if (speciesLower.includes('succulent') || speciesLower.includes('cactus') || speciesLower.includes('aloe')) {
    tips.push('<strong>Method:</strong> Soak thoroughly, let drain completely. Wait until soil is bone dry before next watering.')
  } else if (speciesLower.includes('fern') || speciesLower.includes('calathea') || speciesLower.includes('prayer')) {
    tips.push('<strong>Method:</strong> Keep soil consistently moist but not soggy. Use room temperature water.')
  } else if (speciesLower.includes('orchid')) {
    tips.push('<strong>Method:</strong> Water roots thoroughly, let drain. Ice cube method works for phalaenopsis.')
  } else if (speciesLower.includes('monstera') || speciesLower.includes('philodendron') || speciesLower.includes('pothos')) {
    tips.push('<strong>Method:</strong> Water when top 1-2 inches are dry. These like to dry out slightly between waterings.')
  } else if (speciesLower.includes('fiddle') || speciesLower.includes('ficus')) {
    tips.push('<strong>Method:</strong> Water when top inch is dry. Be consistent - they hate schedule changes.')
  } else {
    tips.push('<strong>Method:</strong> Water until it drains from bottom. Empty saucer after 30 minutes.')
  }

  if (soilType === 'succulent') {
    tips.push('<strong>Soil note:</strong> Fast-draining mix means water will run through quickly - that\'s normal.')
  }

  tips.push('<strong>Finger test:</strong> Insert finger 1-2 inches deep - if dry, it\'s time to water.')
  tips.push('<strong>Moisture meter:</strong> Insert probe halfway into pot. Water when reading is 2-3 (most plants) or 1 (succulents/cacti).')

  return tips
}

function getFertilizingTips(potSize, species) {
  const amounts = {
    small: '1/4 strength solution',
    medium: '1/2 strength solution',
    large: '1/2 to full strength',
    xlarge: 'Full strength'
  }

  let tips = [`<strong>Amount:</strong> ${amounts[potSize] || amounts.medium}`]

  const speciesLower = species.toLowerCase()
  if (speciesLower.includes('succulent') || speciesLower.includes('cactus')) {
    tips.push('<strong>Type:</strong> Use cactus/succulent fertilizer (low nitrogen). Only fertilize during growing season.')
  } else if (speciesLower.includes('orchid')) {
    tips.push('<strong>Type:</strong> Use orchid-specific fertilizer. "Weekly, weakly" approach works best.')
  } else if (speciesLower.includes('fern')) {
    tips.push('<strong>Type:</strong> Use balanced liquid fertilizer at half strength. Ferns are light feeders.')
  } else {
    tips.push('<strong>Type:</strong> Balanced liquid fertilizer (10-10-10 or similar). Dilute according to package.')
  }

  tips.push('<strong>When:</strong> Apply to moist soil, never dry. Water lightly first if soil is dry.')
  tips.push('<strong>Tip:</strong> Skip fertilizing in winter when growth slows.')

  return tips
}

function getTrimmingTips(species) {
  let tips = []

  const speciesLower = species.toLowerCase()
  if (speciesLower.includes('pothos') || speciesLower.includes('philodendron')) {
    tips.push('<strong>Where:</strong> Cut just above a node (bump on stem). This encourages branching.')
    tips.push('<strong>Tip:</strong> Cuttings can be propagated in water!')
  } else if (speciesLower.includes('ficus') || speciesLower.includes('rubber')) {
    tips.push('<strong>Caution:</strong> Wipe milky sap with damp cloth - it can irritate skin.')
    tips.push('<strong>Where:</strong> Cut back to a node or leaf. New growth will emerge below cut.')
  } else if (speciesLower.includes('succulent')) {
    tips.push('<strong>Method:</strong> Remove dead/dried lower leaves by gently pulling downward.')
    tips.push('<strong>Tip:</strong> Healthy leaves removed can be propagated!')
  } else {
    tips.push('<strong>Tools:</strong> Use clean, sharp scissors or pruning shears.')
    tips.push('<strong>What to remove:</strong> Yellow/brown leaves, leggy growth, dead stems.')
  }

  tips.push('<strong>Hygiene:</strong> Clean tools with rubbing alcohol between plants to prevent disease spread.')

  return tips
}

function getRepottingTips(potSize, species) {
  const newSizes = {
    small: 'medium (1-2 inches larger diameter)',
    medium: 'large (1-2 inches larger diameter)',
    large: 'xlarge (2-3 inches larger diameter)',
    xlarge: 'same size with fresh soil, or divide'
  }

  let tips = [`<strong>New pot:</strong> ${newSizes[potSize] || 'Go up 1-2 inches in diameter'}`]

  const speciesLower = species.toLowerCase()
  if (speciesLower.includes('succulent') || speciesLower.includes('cactus')) {
    tips.push('<strong>Soil:</strong> Cactus/succulent mix. Add extra perlite for drainage.')
  } else if (speciesLower.includes('orchid')) {
    tips.push('<strong>Soil:</strong> Orchid bark mix only - never regular potting soil!')
  } else if (speciesLower.includes('fern')) {
    tips.push('<strong>Soil:</strong> Peat-based mix with good moisture retention.')
  } else {
    tips.push('<strong>Soil:</strong> Quality potting mix. Add perlite for drainage if needed.')
  }

  tips.push('<strong>Process:</strong> Water 1-2 days before repotting. Gently loosen root ball.')
  tips.push('<strong>After:</strong> Water thoroughly and keep in indirect light for a week while it adjusts.')

  return tips
}

function getRotatingTips() {
  return [
    '<strong>How much:</strong> Rotate 1/4 turn (90 degrees).',
    '<strong>Why:</strong> Ensures even light exposure for balanced growth.',
    '<strong>Frequency:</strong> Every watering or weekly for best results.',
    '<strong>Tip:</strong> Mark the pot so you remember which way to turn.'
  ]
}

function getMistingTips(species) {
  let tips = ['<strong>When:</strong> Morning is best - leaves dry before evening.']

  const speciesLower = species.toLowerCase()
  if (speciesLower.includes('fern') || speciesLower.includes('calathea') || speciesLower.includes('prayer')) {
    tips.push('<strong>Frequency:</strong> Daily or every other day. These love humidity!')
  } else if (speciesLower.includes('succulent') || speciesLower.includes('cactus')) {
    tips.push('<strong>Caution:</strong> Skip misting! These prefer dry conditions.')
    return tips
  } else {
    tips.push('<strong>Frequency:</strong> 2-3 times per week, more in dry/heated rooms.')
  }

  tips.push('<strong>Method:</strong> Use room temperature water. Mist around and above the plant.')
  tips.push('<strong>Alternative:</strong> Pebble tray with water works great for constant humidity.')

  return tips
}

function getCheckTips() {
  return [
    '<strong>Inspect:</strong> Look under leaves for pests (tiny dots, webs, sticky residue).',
    '<strong>Feel soil:</strong> Check moisture level 1-2 inches deep.',
    '<strong>New growth:</strong> Look for new leaves or stems emerging.',
    '<strong>Problem signs:</strong> Yellowing, browning tips, drooping, or spots.',
    '<strong>Tip:</strong> Take a photo to track changes over time!'
  ]
}

async function complete() {
  if (loading.value || props.completed) return
  loading.value = true
  try {
    await tasks.completeTask(props.task.id)
    window.$toast?.success('Task completed!')
  } catch (error) {
    window.$toast?.error(error.message)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="card" :class="{ 'opacity-60': completed }">
    <div class="p-4 flex items-start gap-4">
      <!-- Checkbox -->
      <button
        @click="complete"
        :disabled="loading || completed"
        class="w-6 h-6 rounded-full border-2 flex-shrink-0 flex items-center justify-center transition-all"
        :class="completed
          ? 'bg-plant-500 border-plant-500'
          : 'border-gray-300 hover:border-plant-500'"
      >
        <svg v-if="completed" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <div v-else-if="loading" class="w-4 h-4 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
      </button>

      <!-- Content -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <span class="text-lg">{{ taskIcons[task.task_type] || '📋' }}</span>
          <span class="font-medium text-gray-900 capitalize">{{ task.task_type }}</span>
          <span
            v-if="task.priority === 'high' || task.priority === 'urgent'"
            class="px-2 py-0.5 text-xs font-medium rounded-full"
            :class="task.priority === 'urgent' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'"
          >
            {{ task.priority }}
          </span>
        </div>

        <p v-if="plant || task.plant_name" class="text-sm text-gray-500 truncate">
          {{ plant?.name || task.plant_name }}
          <span v-if="plant?.location_name || task.plant_location" class="text-gray-400">· {{ plant?.location_name || task.plant_location }}</span>
        </p>

        <p v-if="task.instructions" class="text-sm text-gray-600 mt-2">
          {{ task.instructions }}
        </p>

        <!-- Expand/collapse button for recommendations -->
        <button
          v-if="showRecommendations && careRecommendations && !completed"
          @click="expanded = !expanded"
          class="mt-2 text-xs text-plant-600 hover:text-plant-700 font-medium flex items-center gap-1"
        >
          <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
          {{ expanded ? 'Hide' : 'Show' }} care tips
        </button>
      </div>

      <!-- Plant thumbnail -->
      <div v-if="plant?.thumbnail || task.plant_thumbnail" class="w-12 h-12 rounded-lg overflow-hidden flex-shrink-0">
        <img :src="`/uploads/plants/${plant?.thumbnail || task.plant_thumbnail}`" :alt="plant?.name || task.plant_name" class="w-full h-full object-cover">
      </div>
    </div>

    <!-- Care recommendations (expandable) -->
    <div v-if="expanded && careRecommendations" class="px-4 pb-4 pt-0">
      <div class="bg-plant-50 rounded-xl p-3 text-sm space-y-2 border border-plant-100">
        <p class="font-medium text-plant-800 flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Care Tips
        </p>
        <ul class="space-y-1.5 text-gray-700">
          <li v-for="(tip, index) in careRecommendations" :key="index" class="leading-snug" v-html="tip"></li>
        </ul>
      </div>
    </div>
  </div>
</template>

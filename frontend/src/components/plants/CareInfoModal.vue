<script setup>
import { ref, watch } from 'vue'
import { useApi } from '@/composables/useApi'

const props = defineProps({
  plantId: { type: [Number, String], required: true },
  plantName: { type: String, default: '' },
  visible: { type: Boolean, default: false }
})

const emit = defineEmits(['close'])

const api = useApi()
const loading = ref(false)
const error = ref(null)
const careInfo = ref(null)
const species = ref('')

watch(() => props.visible, async (visible) => {
  if (visible && !careInfo.value) {
    await loadCareInfo()
  }
})

async function loadCareInfo() {
  loading.value = true
  error.value = null
  try {
    const response = await api.get(`/plants/${props.plantId}/care-info`)
    careInfo.value = response.care_info
    species.value = response.species
  } catch (e) {
    error.value = e.message || 'Failed to load care information'
  } finally {
    loading.value = false
  }
}

function close() {
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
    >
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/50" @click="close"></div>

      <!-- Modal -->
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg max-h-[85vh] overflow-hidden flex flex-col">
        <!-- Header -->
        <div class="sticky top-0 bg-white border-b px-4 py-3 flex items-center justify-between z-10">
          <div>
            <h2 class="font-semibold text-gray-900">Care Guide</h2>
            <p class="text-sm text-gray-500">{{ species || plantName }}</p>
          </div>
          <button @click="close" class="p-2 text-gray-400 hover:text-gray-600 -mr-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-4">
          <!-- Loading -->
          <div v-if="loading" class="flex items-center justify-center py-12">
            <div class="flex items-center gap-3 text-plant-600">
              <div class="w-6 h-6 border-2 border-plant-500 border-t-transparent rounded-full animate-spin"></div>
              <span>Generating care guide...</span>
            </div>
          </div>

          <!-- Error -->
          <div v-else-if="error" class="py-8 text-center">
            <div class="w-12 h-12 mx-auto mb-4 bg-red-100 rounded-full flex items-center justify-center">
              <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <p class="text-red-600 mb-4">{{ error }}</p>
            <button @click="loadCareInfo" class="btn-secondary text-sm">Try Again</button>
          </div>

          <!-- Care Info Content -->
          <div v-else-if="careInfo" class="space-y-6">
            <!-- Plant Identity -->
            <div class="bg-plant-50 rounded-xl p-4 border border-plant-100">
              <h3 class="font-semibold text-plant-800 mb-2">{{ careInfo.common_name }}</h3>
              <p class="text-sm text-plant-600 italic">{{ careInfo.scientific_name }}</p>
              <div class="flex flex-wrap gap-2 mt-2 text-xs">
                <span class="px-2 py-1 bg-plant-100 text-plant-700 rounded">{{ careInfo.family }}</span>
                <span class="px-2 py-1 bg-cream-100 text-charcoal-600 rounded">{{ careInfo.origin }}</span>
              </div>
            </div>

            <!-- Light -->
            <section v-if="careInfo.light">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#9728;</span> Light
              </h4>
              <div class="bg-gray-50 rounded-xl p-3 space-y-2 text-sm">
                <p><span class="font-medium">Ideal:</span> {{ careInfo.light.ideal }}</p>

                <!-- Foot-candle ranges -->
                <div v-if="careInfo.light.foot_candles" class="bg-sunny-50 rounded-lg p-2 border border-sunny-100">
                  <p class="text-xs font-medium text-sunny-800 mb-1">Light meter readings (foot-candles):</p>
                  <div class="flex flex-wrap gap-2 text-xs">
                    <span class="px-2 py-0.5 bg-white rounded border text-gray-600">
                      Min: {{ careInfo.light.foot_candles.low }} fc
                    </span>
                    <span class="px-2 py-0.5 bg-plant-100 rounded border border-plant-200 text-plant-700 font-medium">
                      Ideal: {{ careInfo.light.foot_candles.ideal_min }}-{{ careInfo.light.foot_candles.ideal_max }} fc
                    </span>
                    <span class="px-2 py-0.5 bg-white rounded border text-gray-600">
                      Max: {{ careInfo.light.foot_candles.max }} fc
                    </span>
                  </div>
                </div>

                <p v-if="careInfo.light.tolerance"><span class="font-medium">Tolerance:</span> {{ careInfo.light.tolerance }}</p>
                <div v-if="careInfo.light.signs_of_too_much || careInfo.light.signs_of_too_little" class="pt-2 border-t border-gray-200">
                  <p v-if="careInfo.light.signs_of_too_much" class="text-amber-700">
                    <span class="font-medium">Too much:</span> {{ careInfo.light.signs_of_too_much }}
                  </p>
                  <p v-if="careInfo.light.signs_of_too_little" class="text-amber-700">
                    <span class="font-medium">Too little:</span> {{ careInfo.light.signs_of_too_little }}
                  </p>
                </div>
              </div>
            </section>

            <!-- Water -->
            <section v-if="careInfo.water">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#128167;</span> Water
              </h4>
              <div class="bg-gray-50 rounded-xl p-3 space-y-2 text-sm">
                <p><span class="font-medium">Frequency:</span> {{ careInfo.water.frequency }}</p>
                <p v-if="careInfo.water.method"><span class="font-medium">Method:</span> {{ careInfo.water.method }}</p>
                <div v-if="careInfo.water.signs_of_overwatering || careInfo.water.signs_of_underwatering" class="pt-2 border-t border-gray-200">
                  <p v-if="careInfo.water.signs_of_overwatering" class="text-blue-700">
                    <span class="font-medium">Overwatering:</span> {{ careInfo.water.signs_of_overwatering }}
                  </p>
                  <p v-if="careInfo.water.signs_of_underwatering" class="text-orange-700">
                    <span class="font-medium">Underwatering:</span> {{ careInfo.water.signs_of_underwatering }}
                  </p>
                </div>
              </div>
            </section>

            <!-- Temperature & Humidity -->
            <section v-if="careInfo.temperature || careInfo.humidity">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#127777;</span> Environment
              </h4>
              <div class="bg-gray-50 rounded-xl p-3 space-y-2 text-sm">
                <div v-if="careInfo.temperature">
                  <p><span class="font-medium">Temperature:</span> {{ careInfo.temperature.ideal_range }}</p>
                  <p v-if="careInfo.temperature.minimum" class="text-xs text-gray-500">
                    Min: {{ careInfo.temperature.minimum }} | Max: {{ careInfo.temperature.maximum }}
                  </p>
                </div>
                <div v-if="careInfo.humidity">
                  <p><span class="font-medium">Humidity:</span> {{ careInfo.humidity.ideal }}</p>
                  <p v-if="careInfo.humidity.tips" class="text-xs text-gray-500">{{ careInfo.humidity.tips }}</p>
                </div>
              </div>
            </section>

            <!-- Soil & Fertilizer -->
            <section v-if="careInfo.soil || careInfo.fertilizer">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#127793;</span> Soil & Feeding
              </h4>
              <div class="bg-gray-50 rounded-xl p-3 space-y-2 text-sm">
                <div v-if="careInfo.soil">
                  <p><span class="font-medium">Soil:</span> {{ careInfo.soil.type }}</p>
                  <p v-if="careInfo.soil.drainage" class="text-xs text-gray-500">{{ careInfo.soil.drainage }}</p>
                </div>
                <div v-if="careInfo.fertilizer" class="pt-2 border-t border-gray-200">
                  <p><span class="font-medium">Fertilizer:</span> {{ careInfo.fertilizer.type }}</p>
                  <p class="text-xs text-gray-500">
                    {{ careInfo.fertilizer.frequency }}
                    <span v-if="careInfo.fertilizer.season"> - Best in {{ careInfo.fertilizer.season }}</span>
                  </p>
                </div>
              </div>
            </section>

            <!-- Toxicity Warning -->
            <section v-if="careInfo.toxicity && (careInfo.toxicity.toxic_to_pets || careInfo.toxicity.toxic_to_humans)">
              <div class="bg-red-50 rounded-xl p-3 border border-red-200">
                <h4 class="font-medium text-red-800 mb-1 flex items-center gap-2">
                  <span class="text-lg">&#9888;</span> Toxicity Warning
                </h4>
                <div class="text-sm text-red-700 space-y-1">
                  <p v-if="careInfo.toxicity.toxic_to_pets">Toxic to pets</p>
                  <p v-if="careInfo.toxicity.toxic_to_humans">Toxic to humans</p>
                  <p v-if="careInfo.toxicity.details" class="text-xs">{{ careInfo.toxicity.details }}</p>
                </div>
              </div>
            </section>

            <!-- Common Issues -->
            <section v-if="careInfo.common_issues?.length">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#9888;</span> Common Issues
              </h4>
              <div class="space-y-2">
                <div
                  v-for="(issue, index) in careInfo.common_issues"
                  :key="index"
                  class="bg-amber-50 rounded-xl p-3 text-sm border border-amber-100"
                >
                  <p class="font-medium text-amber-800">{{ issue.issue }}</p>
                  <p v-if="issue.cause" class="text-amber-700 text-xs mt-1">
                    <span class="font-medium">Cause:</span> {{ issue.cause }}
                  </p>
                  <p v-if="issue.solution" class="text-green-700 text-xs mt-1">
                    <span class="font-medium">Solution:</span> {{ issue.solution }}
                  </p>
                </div>
              </div>
            </section>

            <!-- Care Tips -->
            <section v-if="careInfo.care_tips?.length">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#128161;</span> Care Tips
              </h4>
              <ul class="bg-green-50 rounded-xl p-3 space-y-2 text-sm text-green-800 border border-green-100">
                <li v-for="(tip, index) in careInfo.care_tips" :key="index" class="flex gap-2">
                  <span class="text-green-500">&#10003;</span>
                  <span>{{ tip }}</span>
                </li>
              </ul>
            </section>

            <!-- Growth Info -->
            <section v-if="careInfo.growth">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#127794;</span> Growth
              </h4>
              <div class="bg-gray-50 rounded-xl p-3 text-sm space-y-1">
                <p v-if="careInfo.growth.rate"><span class="font-medium">Growth rate:</span> {{ careInfo.growth.rate }}</p>
                <p v-if="careInfo.growth.mature_size"><span class="font-medium">Mature size:</span> {{ careInfo.growth.mature_size }}</p>
                <p v-if="careInfo.growth.lifespan"><span class="font-medium">Lifespan:</span> {{ careInfo.growth.lifespan }}</p>
              </div>
            </section>

            <!-- Propagation -->
            <section v-if="careInfo.propagation">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#127793;</span> Propagation
              </h4>
              <div class="bg-gray-50 rounded-xl p-3 text-sm space-y-1">
                <p v-if="careInfo.propagation.methods">
                  <span class="font-medium">Methods:</span> {{ careInfo.propagation.methods.join(', ') }}
                </p>
                <p v-if="careInfo.propagation.difficulty">
                  <span class="font-medium">Difficulty:</span> {{ careInfo.propagation.difficulty }}
                </p>
                <p v-if="careInfo.propagation.tips" class="text-xs text-gray-500">{{ careInfo.propagation.tips }}</p>
              </div>
            </section>

            <!-- Fun Facts -->
            <section v-if="careInfo.fun_facts?.length">
              <h4 class="font-medium text-gray-900 mb-2 flex items-center gap-2">
                <span class="text-lg">&#10024;</span> Fun Facts
              </h4>
              <ul class="bg-purple-50 rounded-xl p-3 space-y-2 text-sm text-purple-800 border border-purple-100">
                <li v-for="(fact, index) in careInfo.fun_facts" :key="index">{{ fact }}</li>
              </ul>
            </section>
          </div>

          <!-- No Care Info -->
          <div v-else class="py-8 text-center text-gray-500">
            <p>No care information available for this plant.</p>
            <p class="text-sm mt-2">Make sure a species has been identified.</p>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

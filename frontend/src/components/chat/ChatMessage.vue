<script setup>
import { computed } from 'vue'

const props = defineProps({
  message: { type: Object, required: true }
})

const isUser = computed(() => props.message.role === 'user')

const formattedTime = computed(() => {
  if (!props.message.created_at) return ''
  const date = new Date(props.message.created_at)
  return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
})

const providerLabel = computed(() => {
  if (!props.message.provider) return ''
  return props.message.provider === 'openai' ? 'GPT-5.2' : 'Claude'
})
</script>

<template>
  <div class="flex items-start gap-3" :class="isUser ? 'flex-row-reverse' : ''">
    <!-- Avatar -->
    <div
      class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
      :class="isUser ? 'bg-blue-100' : 'bg-plant-100'"
    >
      <svg v-if="isUser" class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
      </svg>
      <svg v-else class="w-4 h-4 text-plant-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
      </svg>
    </div>

    <!-- Message bubble -->
    <div class="max-w-[80%]">
      <div
        class="rounded-2xl px-4 py-2"
        :class="isUser
          ? 'bg-blue-500 text-white rounded-tr-sm'
          : 'bg-gray-100 text-gray-900 rounded-tl-sm'"
      >
        <p class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
      </div>
      <div class="flex items-center gap-2 mt-1 px-1">
        <span class="text-xs text-gray-400">{{ formattedTime }}</span>
        <span v-if="providerLabel && !isUser" class="text-xs text-gray-400">
          via {{ providerLabel }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'

const route = useRoute()
const router = useRouter()
const tasks = useTasksStore()

const navItems = [
  { name: 'dashboard', icon: 'home', label: 'Today' },
  { name: 'plants', icon: 'plant', label: 'Plants' },
  { name: 'add-plant', icon: 'plus', label: 'Add' },
  { name: 'sitter-setup', icon: 'share', label: 'Sitter' },
  { name: 'settings', icon: 'settings', label: 'Settings' }
]

const currentRoute = computed(() => route.name)

function navigate(name) {
  router.push({ name })
}
</script>

<template>
  <nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 safe-bottom">
    <div class="flex items-center justify-around h-16 max-w-lg mx-auto">
      <button
        v-for="item in navItems"
        :key="item.name"
        @click="navigate(item.name)"
        class="flex flex-col items-center justify-center w-16 h-full transition-colors"
        :class="currentRoute === item.name ? 'text-plant-600' : 'text-gray-400'"
      >
        <!-- Home icon -->
        <svg v-if="item.icon === 'home'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </svg>

        <!-- Plant icon -->
        <svg v-else-if="item.icon === 'plant'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V6M12 6c-1.5-2-4-3-6-2 2.5.5 4 2.5 5 4.5M12 6c1.5-2 4-3 6-2-2.5.5-4 2.5-5 4.5M8 21h8" />
        </svg>

        <!-- Plus icon -->
        <svg v-else-if="item.icon === 'plus'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>

        <!-- Share icon -->
        <svg v-else-if="item.icon === 'share'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
        </svg>

        <!-- Settings icon -->
        <svg v-else-if="item.icon === 'settings'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>

        <span class="text-xs mt-1 font-medium">{{ item.label }}</span>

        <!-- Badge for tasks -->
        <span
          v-if="item.name === 'dashboard' && tasks.todayCount > 0"
          class="absolute top-1 ml-6 bg-plant-500 text-white text-xs w-5 h-5 flex items-center justify-center rounded-full"
        >
          {{ tasks.todayCount > 9 ? '9+' : tasks.todayCount }}
        </span>
      </button>
    </div>
  </nav>
</template>

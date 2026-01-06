<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTasksStore } from '@/stores/tasks'

const route = useRoute()
const router = useRouter()
const tasks = useTasksStore()

const navItems = [
  { name: 'dashboard', icon: 'home', label: 'Today', doodle: 'https://img.icons8.com/doodle/48/home.png' },
  { name: 'plants', icon: 'plant', label: 'Plants', doodle: 'https://img.icons8.com/doodle/48/potted-plant--v1.png' },
  { name: 'add-plant', icon: 'plus', label: 'Add', doodle: 'https://img.icons8.com/doodle/48/add.png' },
  { name: 'sitter-setup', icon: 'share', label: 'Sitter', doodle: 'https://img.icons8.com/doodle/48/share--v1.png' },
  { name: 'settings', icon: 'settings', label: 'Settings', doodle: 'https://img.icons8.com/doodle/48/settings.png' }
]

const currentRoute = computed(() => route.name)

function navigate(name) {
  router.push({ name })
}
</script>

<template>
  <nav class="bottom-nav">
    <div class="flex items-center justify-around h-16 max-w-lg mx-auto">
      <button
        v-for="item in navItems"
        :key="item.name"
        @click="navigate(item.name)"
        class="bottom-nav-item relative"
        :class="{ 'active': currentRoute === item.name }"
      >
        <!-- Plus icon (special add button) -->
        <img
          v-if="item.icon === 'plus'"
          :src="item.doodle"
          :alt="item.label"
          class="w-10 h-10 -mt-2 transition-transform hover:scale-110"
        >

        <!-- Regular nav icons -->
        <img
          v-else
          :src="item.doodle"
          :alt="item.label"
          class="w-7 h-7 nav-icon transition-transform"
          :class="{ 'scale-110': currentRoute === item.name }"
        >

        <span v-if="item.icon !== 'plus'" class="text-xs mt-1 font-semibold">{{ item.label }}</span>

        <!-- Badge for tasks -->
        <span
          v-if="item.name === 'dashboard' && tasks.todayCount > 0"
          class="absolute -top-0.5 ml-5 bg-terracotta-500 text-white text-xs w-5 h-5 flex items-center justify-center rounded-full font-bold shadow-warm"
        >
          {{ tasks.todayCount > 9 ? '9+' : tasks.todayCount }}
        </span>
      </button>
    </div>
  </nav>
</template>

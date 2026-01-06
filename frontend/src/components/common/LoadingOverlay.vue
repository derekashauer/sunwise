<script setup>
defineProps({
  visible: { type: Boolean, default: false },
  message: { type: String, default: 'Loading...' }
})
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="visible"
        class="fixed inset-0 bg-white/90 backdrop-blur-sm flex flex-col items-center justify-center z-[100]"
      >
        <!-- Animated Icons8 image -->
        <div class="mb-6">
          <img
            src="https://img.icons8.com/doodle/96/watering-can.png"
            alt="loading"
            class="w-20 h-20 animate-bounce"
          >
        </div>

        <!-- Loading message -->
        <p class="text-plant-700 font-medium text-lg animate-pulse">{{ message }}</p>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>

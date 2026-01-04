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
        <!-- Animated growing plant -->
        <div class="plant-container relative mb-6">
          <!-- Pot -->
          <div class="pot">
            <div class="pot-top"></div>
            <div class="pot-body"></div>
          </div>

          <!-- Growing plant -->
          <div class="plant">
            <!-- Stem -->
            <div class="stem"></div>

            <!-- Leaves that grow -->
            <div class="leaf leaf-1"></div>
            <div class="leaf leaf-2"></div>
            <div class="leaf leaf-3"></div>
            <div class="leaf leaf-4"></div>
          </div>
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

.plant-container {
  width: 120px;
  height: 160px;
  position: relative;
}

/* Pot styles */
.pot {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
}

.pot-top {
  width: 60px;
  height: 8px;
  background: linear-gradient(180deg, #d97706 0%, #b45309 100%);
  border-radius: 4px 4px 0 0;
  margin-left: -4px;
}

.pot-body {
  width: 52px;
  height: 36px;
  background: linear-gradient(180deg, #b45309 0%, #92400e 100%);
  border-radius: 0 0 8px 8px;
  position: relative;
}

.pot-body::after {
  content: '';
  position: absolute;
  top: 4px;
  left: 4px;
  right: 4px;
  height: 8px;
  background: #451a03;
  border-radius: 4px;
}

/* Plant styles */
.plant {
  position: absolute;
  bottom: 44px;
  left: 50%;
  transform: translateX(-50%);
}

/* Stem that grows up */
.stem {
  width: 6px;
  height: 0;
  background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
  border-radius: 3px;
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  transform-origin: bottom center;
  animation: grow-stem 2s ease-out infinite;
}

/* Leaves */
.leaf {
  position: absolute;
  width: 24px;
  height: 16px;
  background: linear-gradient(135deg, #4ade80 0%, #22c55e 100%);
  border-radius: 50% 0;
  opacity: 0;
  transform-origin: bottom left;
}

.leaf::before {
  content: '';
  position: absolute;
  width: 1px;
  height: 80%;
  background: #16a34a;
  left: 30%;
  top: 10%;
  transform: rotate(45deg);
}

.leaf-1 {
  left: 3px;
  bottom: 20px;
  transform: rotate(-30deg) scale(0);
  animation: grow-leaf 2s ease-out infinite 0.5s;
}

.leaf-2 {
  right: 3px;
  left: auto;
  bottom: 35px;
  transform: rotate(210deg) scale(0);
  transform-origin: bottom right;
  animation: grow-leaf-right 2s ease-out infinite 0.8s;
}

.leaf-3 {
  left: 3px;
  bottom: 50px;
  transform: rotate(-40deg) scale(0);
  animation: grow-leaf 2s ease-out infinite 1.1s;
}

.leaf-4 {
  right: 3px;
  left: auto;
  bottom: 65px;
  transform: rotate(220deg) scale(0);
  transform-origin: bottom right;
  animation: grow-leaf-right 2s ease-out infinite 1.4s;
}

@keyframes grow-stem {
  0% {
    height: 0;
  }
  50% {
    height: 70px;
  }
  100% {
    height: 70px;
  }
}

@keyframes grow-leaf {
  0%, 25% {
    opacity: 0;
    transform: rotate(-30deg) scale(0);
  }
  40%, 80% {
    opacity: 1;
    transform: rotate(-30deg) scale(1);
  }
  100% {
    opacity: 0;
    transform: rotate(-30deg) scale(1);
  }
}

@keyframes grow-leaf-right {
  0%, 25% {
    opacity: 0;
    transform: rotate(210deg) scale(0);
  }
  40%, 80% {
    opacity: 1;
    transform: rotate(210deg) scale(1);
  }
  100% {
    opacity: 0;
    transform: rotate(210deg) scale(1);
  }
}
</style>

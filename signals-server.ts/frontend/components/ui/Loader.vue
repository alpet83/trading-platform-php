<template>
  <div class="flex justify-center items-top min-h-2">
    <div class="flex space-x-2">
      <span :style="{ backgroundColor: color || '#3758F9' }" class="dot"></span>
      <span :style="{ backgroundColor: color || '#3758F9' }" class="dot delay-150"></span>
      <span :style="{ backgroundColor: color || '#3758F9' }" class="dot delay-300"></span>
    </div>
  </div>
</template>
<script>
export default {
  props: {
    color: {
      type: String,
      default: "#3758F9",
    },
  },
};
</script>
<style scoped>
.dot {
  width: 5px;
  height: 5px;
  border-radius: 9999px;
  animation: bounce 0.6s infinite ease-in-out;
}

.delay-150 {
  animation-delay: 0.15s;
}

.delay-300 {
  animation-delay: 0.3s;
}

@keyframes bounce {
  0%,
  80%,
  100% {
    transform: translateY(0);
  }
  40% {
    transform: translateY(-10px);
  }
}
</style>
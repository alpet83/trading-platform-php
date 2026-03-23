<template>
  <svg
      v-if="isEnabled"
      class="w-5 h-5 text-green-600 inline"
      viewBox="0 0 24 24"
      fill="currentColor"
  >
    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
  </svg>

  <svg
      v-else
      class="w-5 h-5 text-red-600 inline"
      viewBox="0 0 24 24"
      fill="currentColor"
  >
    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
  </svg>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  value: [String, Number, Boolean]
})

const isEnabled = computed(() =>
    props.value === 1 || props.value === '1' || props.value === true
)
</script>
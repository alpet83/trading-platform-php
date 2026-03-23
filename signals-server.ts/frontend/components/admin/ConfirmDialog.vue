<template>
  <transition name="fade">
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50"
    >
      <div class="bg-white rounded-lg shadow-lg w-96 p-6">
        <h3 class="text-lg font-semibold mb-4 dark:text-black">{{ title }}</h3>
        <p class="text-gray-600 mb-6">{{ message }}</p>

        <div class="flex justify-between gap-3">
          <button
              @click="cancel"
              class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300 dark:text-black"
          >
            Отмена
          </button>
          <button
              @click="confirmAction"
              class="px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700"
          >
            Подтвердить
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { defineEmits, defineProps } from 'vue'

const props = defineProps({
  title: { type: String, default: 'Подтверждение' },
  message: { type: String, default: 'Вы уверены?' },
})

const emit = defineEmits(['confirm', 'cancel'])

function confirmAction() {
  emit('confirm')
}

function cancel() {
  emit('cancel')
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
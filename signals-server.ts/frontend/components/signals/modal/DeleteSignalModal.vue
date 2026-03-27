<template>
  <div class="relative">
    <div
        class="absolute top-[35px] right-0 z-50 w-[340px] p-4 rounded-2xl bg-white shadow-md text-center sm:w-[280px] sm:p-3"
    >
      <span class="block text-[20px] leading-[120%] uppercase font-semibold mb-2">
        {{$t('deleteConfirm.title')}}
      </span>
      <span class="block text-[24px] leading-[120%] uppercase font-semibold text-gray-700 mb-4">
        ID {{id}}
      </span>
      <div class="flex justify-between items-center gap-2 mt-5">
        <button @click="$emit('confirm')" class="bg-red-500 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors">
          {{$t('deleteConfirm.delete')}}
        </button>
        <button @click="$emit('close')" class="border border-blue-500 text-blue-600 hover:bg-blue-500 hover:text-white px-4 py-2 rounded-lg transition-colors">
          {{$t('deleteConfirm.cancel')}}
        </button>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
defineEmits(['close', 'confirm']);
const props = defineProps({
  id: {type: String, default: 0},
})
</script>
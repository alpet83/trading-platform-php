<template>
  <div>
  </div>
</template>

<script setup>
import { useApiRequest } from '~/composables/api'
definePageMeta({
  layout: "centered",
});
const { data } = await useApiRequest('/api/auth/active')
const rawData = data.value
if (rawData.base) {
  navigateTo('/auth/base')
}
if (rawData.telegram) {
  navigateTo('/auth/telegram')
}
</script>

<style scoped>

</style>

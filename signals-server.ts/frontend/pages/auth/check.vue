<script setup lang="ts">
const { data } = useApiRequest("/api/auth/check");
</script>

<template>
  <template v-if="data"> Api result: {{ data }} </template>
</template>

<style scoped></style>
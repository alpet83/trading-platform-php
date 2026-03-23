<template>
  <div></div>
</template>

<script>
import { navigateTo } from "#app";

export default {
  async beforeMount() {
    await navigateTo("/users");
  },
};
</script>

<style scoped></style>

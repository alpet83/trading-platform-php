<template>
  <v-app>
      <v-container fill-height>
          <slot />
      </v-container>
  </v-app>
</template>

<script>
export default {
  name: 'centered',
};
</script>

<style scoped></style>

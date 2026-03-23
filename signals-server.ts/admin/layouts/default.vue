<template>
  <v-layout class="rounded rounded-md">
    <v-app-bar>
      <v-icon icon="mdi-menu" @click="drawer = !drawer"></v-icon>
      <v-spacer></v-spacer>
      <v-btn @click="exit">Logout</v-btn>
    </v-app-bar>

    <v-navigation-drawer v-model="drawer">
      <nuxt-link v-for="el in links" :to="el.to">
        <v-list>
          <v-list-item :title="el.title"></v-list-item>
        </v-list>
      </nuxt-link>
    </v-navigation-drawer>
    <v-main>
      <div>
        <slot />
      </div>
    </v-main>
  </v-layout>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { useCookie } from "#app";
import { useApiRequest } from "~/composables/api";

export default defineComponent({
  name: "default",
  async beforeMount() {
    await useApiRequest("/api/users/checkAdmin", {
      method: "GET",
    });
  },
  data() {
    return {
      drawer: true,
      links: [
        { to: "/users", title: "Users" },
      ],
    };
  },
  methods: {
    async exit() {
      let token = await useCookie("token");
      token.value = "";
      await navigateTo("/login");
    },
  },
});
</script>

<style scoped></style>

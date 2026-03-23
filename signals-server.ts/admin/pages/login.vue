<template>
      <v-card>
        <v-toolbar color="primary">
          <v-toolbar-title>Login</v-toolbar-title>
        </v-toolbar>
        <v-card-text>
          <v-text-field v-model="username" outline label="Username" />
          <v-text-field
            v-model="password"
            outline
            label="Password"
            type="password"
          />
          <v-btn @click="login" block> Sign in </v-btn>
          <span class="error_message">{{ errorMessage }}</span>
        </v-card-text>
      </v-card>
</template>
<script>
import {useApiRequest} from "~/composables/api";
import {useCookie} from "#app";

definePageMeta({
  layout: 'centered'
})
export default {
  name: 'Login',
  data() {
    return {
      username: '',
      password: '',
      errorMessage: null,
    };
  },
  methods: {
    async login () {
      try {
        const {data} = await useApiRequest("/api/auth/base/login", {
          method: "POST",
          body: {
            username: this.username,
            password: this.password,
          },
        });
        let token = await useCookie('token')
        token.value = data.value.token
        await this.$router.push('/users')
      } catch (error) {
          this.errorMessage = error.message;
      }
    }
  },
}
</script>

<style scoped></style>

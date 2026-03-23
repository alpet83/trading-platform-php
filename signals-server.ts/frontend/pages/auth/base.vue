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
      <v-checkbox v-model="remember" label="Remember me" />
      <v-btn @click="login" block> Sign in </v-btn>
      <span class="error_message">{{ errorMessage }}</span>
    </v-card-text>
  </v-card>
</template>

<script>
definePageMeta({
  layout: "centered",
});
export default {
  name: "base",
  data() {
    return {
      username: '',
      password: '',
      errorMessage: null,
      remember: true,
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
            remember: this.remember,
          },
        });
        let tokenBase = await tCookie('tokenBase', { maxAge: 60*60*24*365 })
        const refreshTokenBase = await tCookie('refreshTokenBase', { maxAge: 60*60*24*365 })
        refreshTokenBase.value = data.value.refreshToken
        tokenBase.value = data.value.token
        await this.$router.push('/')
      } catch (error) {
        this.errorMessage = error.message;
      }
    }
  },
}
</script>
<style scoped>

</style>

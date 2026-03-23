<template>
  <div>
    <div class="relative cursor-pointer w-full flex justify-center">
      <slot />
      <div ref="telegram" class="absolute z-10 w-[40px] inset-0 opacity-0 cursor-pointer pointer-events-auto ml-[80px]"></div>
    </div>
    <span v-if="errorMessage" class="text-red-500 text-sm block mt-4 text-center">
      {{ errorMessage }}
  </span>
  </div>
</template>

<script lang="ts">

type AuthDataResponse = {
  username: string
}
type GetProfileResponse = {
  jwt: string
}
interface TelegramUserAuthData {
  id: number;
  first_name: string;
  last_name?: string;
  username?: string;
  photo_url?: string;
  auth_date: number;
  hash: string;
}
declare global {
  interface Window {
    onTelegramAuth: (data: TelegramUserAuthData) => Promise<void>;
  }
}
export default {
  name: "TelegramAuth",
  data() {
    const config = useRuntimeConfig();
    return {
      errorMessage: "",
      homePath: (config.public.appBasePath || '') + '/',
    };
  },
  props: {
    botUsername: {
      type: String,
      default: ""
    },
    customOnSuccess: {
      type: Boolean,
      default: false
    }
  },
  async mounted() {
    const script: HTMLScriptElement = document.createElement("script");
    script.async = true;
    script.src = "https://telegram.org/js/telegram-widget.js";

    script.setAttribute("data-size", "large");
    script.setAttribute("data-telegram-login", this.botUsername);
    script.setAttribute("data-request-access", "read");
    script.setAttribute("data-onauth", "window.onTelegramAuth(user)");

    window.onTelegramAuth = async (data: Record<string, any>) => {
      try {
        console.log("Данные от Telegram:", data);
        const response = await useApiRequest(`/user/telegram`, {
          method: "POST",
          body: JSON.stringify(data),
        });
        if (response.status.value !== 'success') {
          throw new Error('Ошибка при авторизации на сервере: ' + JSON.stringify(response.error));
        }
        // Сохраняем токен в куку
        const authCookie = tCookie("tokenTelegram");
        authCookie.value = response.data.value.token;
        // Перенаправляем на главную страницу после авторизации
        window.location.href = this.homePath;
      } catch (err) {
        console.error("Ошибка авторизации через Telegram:", err);
        this.errorMessage = "Ошибка авторизации через Telegram";
      }
    };

    (this.$refs.telegram as HTMLDivElement).appendChild(script);
  }
};
</script>
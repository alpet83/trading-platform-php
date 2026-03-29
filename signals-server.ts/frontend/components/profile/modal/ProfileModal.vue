<template>
  <div class="profile-r">
    <div class="profile-r__inner">
      <div class="profile-r__t">
        <div @click="$emit('close')" class="profile-r__close">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
            <path d="M12.6668 3.33325L3.3335 12.6666M3.3335 3.33325L12.6668 12.6666" stroke="#D40D0D" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>

        <div class="profile-r__top">
          <div class="profile-r__ava">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
              <path d="M6.57757 15.4816C5.1628 16.324 1.45336 18.0441 3.71266 20.1966C4.81631 21.248 6.04549 22 7.59087 22H16.4091C17.9545 22 19.1837 21.248 20.2873 20.1966C22.5466 18.0441 18.8372 16.324 17.4224 15.4816C14.1048 13.5061 9.89519 13.5061 6.57757 15.4816Z" stroke="#2F354E" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M16.5 6.5C16.5 8.98528 14.4853 11 12 11C9.51472 11 7.5 8.98528 7.5 6.5C7.5 4.01472 9.51472 2 12 2C14.4853 2 16.5 4.01472 16.5 6.5Z" stroke="#2F354E" stroke-width="1.5"/>
            </svg>
          </div>

          <div class="profile-r__info">
            <div class="profile-r__info-tt">{{ $t('hello') }},</div>
            <div class="profile-r__info-name">{{ user?.username }}</div>
          </div>
        </div>

<!--        <div class="profile-r__lang">-->
<!--          <div-->
<!--              class="profile-r__lang-item"-->
<!--              :class="{ active: locale === 'ru' }"-->
<!--              @click="switchLanguage('ru')"-->
<!--          >-->
<!--            RU-->
<!--          </div>-->
<!--          <div-->
<!--              class="profile-r__lang-item"-->
<!--              :class="{ active: locale === 'en' }"-->
<!--              @click="switchLanguage('en')"-->
<!--          >-->
<!--            ENG-->
<!--          </div>-->
<!--        </div>-->

        <div class="profile-r__menu">
<!--          <a href="#" class="profile-r__menu-item">Change name</a>-->
          <NuxtLink v-if="isAdmin" to="/admin" class="profile-r__menu-item">Admin</NuxtLink>
          <NuxtLink to="/" class="profile-r__menu-item">Signals</NuxtLink>
          <NuxtLink to="/instance" class="profile-r__menu-item">Bot instance manage</NuxtLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import {useApiRequest} from "~/composables/api";

const props = defineProps({
  user: {
    type: Object,
    required: true
  }
})
const isAdmin = ref(false);
const { locale } = useI18n()
onBeforeMount(async () => {
  const res = await useApiRequest('/api/isAdmin', {
    method: 'GET',
  })
  isAdmin.value = res.data.value === 'true';
})
function switchLanguage(lang: string) {
  locale.value = lang
  localStorage.setItem('locale', lang)
}
</script>
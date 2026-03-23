<template>
  <div class="tg-page">
    <div class="tg-page__bg">
      <img src="/svg/bg.svg" alt="">
    </div>

    <div class="tg-page__inner">
      <div class="tg-page__holder">
        <div class="tg-page__top">
          <div class="tg-page__top-tt">Welcome</div>
          <div class="tg-page__top-tx">to an efficient trading system!</div>
        </div>

        <div class="tg-page__tg">
          <div class="tg-page__tg-title">Sign in with Telegram</div>
          <TelegramAuth
              :botUsername="botUsername"
              mode="callback"
          >
            <button class="tg-page__tg-btn" style="cursor: pointer">
              <svg style="cursor: pointer" xmlns="http://www.w3.org/2000/svg" width="36" height="30" viewBox="0 0 36 30" fill="none">
                <path d="M0.9458 13.6753C1.02847 13.6337 1.11119 13.5941 1.19179 13.5567C2.5931 12.9032 4.01302 12.2913 5.43087 11.6794C5.50734 11.6794 5.63545 11.5899 5.70779 11.5608C5.81733 11.5129 5.92689 11.4671 6.03644 11.4192L6.66683 11.1466C7.08846 10.9655 7.508 10.7845 7.92964 10.6034C8.77084 10.2413 9.61203 9.87913 10.4532 9.51491C12.1356 8.79064 13.8201 8.06429 15.5025 7.34001C17.1849 6.61574 18.8694 5.88939 20.5518 5.16511C22.2342 4.44084 23.9187 3.71448 25.6011 2.99021C27.2835 2.26594 28.968 1.53959 30.6504 0.815317C31.0245 0.65298 31.4296 0.411556 31.8305 0.340793C32.1674 0.280437 32.496 0.163887 32.835 0.0993685C33.4778 -0.0234248 34.1867 -0.0733765 34.8026 0.195104C35.0155 0.28876 35.2118 0.419877 35.3751 0.584295C36.1564 1.36268 36.0468 2.64057 35.8815 3.7353C34.7303 11.3651 33.579 18.9971 32.4257 26.6269C32.2687 27.6738 32.0537 28.8226 31.2332 29.4844C30.5387 30.0443 29.5508 30.1067 28.693 29.8695C27.8353 29.6301 27.0789 29.1285 26.3369 28.6353C23.2593 26.5832 20.1798 24.5311 17.1023 22.479C16.3706 21.992 15.5563 21.3551 15.5646 20.4706C15.5687 19.9378 15.8849 19.4633 16.2073 19.0408C18.8818 15.5276 22.7406 13.1134 25.6114 9.76258C26.0165 9.29014 26.3348 8.43683 25.7788 8.16419C25.4481 8.00185 25.0678 8.22246 24.7661 8.43267C20.9713 11.0863 17.1787 13.7419 13.384 16.3955C12.146 17.2613 10.848 18.1521 9.35578 18.3644C8.02061 18.5558 6.67717 18.1812 5.3854 17.7983C4.30238 17.4778 3.22139 17.1489 2.14457 16.8097C1.57206 16.6307 0.980942 16.4371 0.53864 16.0313C0.0963379 15.6254 -0.157848 14.9428 0.108773 14.4017C0.276187 14.0624 0.600686 13.8481 0.941713 13.6732L0.9458 13.6753Z" fill="#00B0F2"/>
              </svg>
              <label style="cursor: pointer">Войти через Telegram</label>
            </button>
          </TelegramAuth>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import TelegramAuth from '~/components/auth/TelegramAuth.vue';
setPageLayout('centered')
const botUsername = ref('trade_adm_auth_bot');
</script>

<style scoped>
.tg-page__tg-btn svg {
  margin-right: 15px;
}
.tg-page__tg-btn {
  width: 100%;
  padding: 15px;
  display: flex;
  align-items: center;
  justify-content: center;

  background: #DDF0F3;
  border-radius: 150px;
  cursor: pointer;
}
.tg-page__tg-title {
  margin-bottom: 10px;
  font-size: 20px;
  line-height: 120%;

  text-transform: uppercase;
}
.tg-page__tg {
  padding: 20px;
  box-sizing: border-box;
  max-width: 345px;
  margin: 50px auto 0;
  width: 100%;

  box-shadow: 0px 4px 250px 0px rgba(0, 0, 0, 0.15);
  background: #FFFFFF;
  border-radius: 20px;
  text-align: center;
}
.tg-page__top-tx {
  text-transform: uppercase;
  font-size: 18px;
  line-height: 120%;
  color: #2F354E;
}
.tg-page__top-tt {
  margin-bottom: 20px;
  font-weight: 600;
  font-size: 24px;
  line-height: 120%;

  text-transform: uppercase;
  color: #2F354E;
}
.tg-page__top {

}
.tg-page__inner {
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: center;

  position: relative;
  z-index: 5;

  text-align: center;
}
.tg-page__bg {
  width: 100%;

  position: absolute;
  top: 0;
  left: 0;
  z-index: 1;
  bottom: 0;
  margin: auto;
  text-align: center;
  display: flex
;
  align-items: center;
  justify-content: center;
}
.tg-page {
  padding: 50px 20px;
  box-sizing: border-box;
  height: 100vh;
  width: 100%;

  background: #F9FEFF;
}


.dark .tg-page__tg-btn {
  background: #0F131C;
}
.dark .tg-page__tg {
  background: #2F354E;
}
.dark .tg-page__top-tt,
.dark .tg-page__top-tx,
.dark .tg-page__tg-title{
  color: #E3E3E3;
}
.dark .tg-page__bg {
  opacity: 0.2;
}
.dark .tg-page {
  background: #1C2331;
}
</style>

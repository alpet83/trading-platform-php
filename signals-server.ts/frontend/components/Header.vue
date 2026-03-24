<template>
  <div class="head-top">
    <div class="tbs h-[50px]">
      <div v-if="route.path === '/'" class="setup-select-wrap">
        <label class="setup-select-label">Setup</label>
        <select class="setup-select" :value="activeTab" @change="onSetupChange">
          <option v-for="n in availableSetups" :key="n" :value="n">{{ n }}</option>
        </select>
      </div>
    </div>
  </div>
  <div class="flex justify-end mt-[10px] mr-[50px] items-center">
    <div>
      <ProfileModal v-if="displayProfileModal" @close="displayProfileModal = !displayProfileModal" :user="user"/>
      <div class="flex items-center ">
        <span>{{$t('theme.light')}}</span>
        <div
            class="relative w-[60px] h-8 mx-4 rounded-full border border-[#E3E3E3] cursor-pointer"
            :class="theme === 'dark' ? 'bg-[#2F354E]' : 'bg-[#E3E3E3]'"
            @click="toggleTheme"
        >
        <span
            class="absolute top-[3px] left-[3px] w-6 h-6 bg-white rounded-full transition-transform duration-300"
            :class="theme === 'dark' ? 'translate-x-[28px]' : ''"
        ></span>
        </div>
        <span>{{$t('theme.dark')}}</span>
      </div>
    </div>

    <button @click="displayProfileModal = !displayProfileModal" class="header__ava">
      <img src="/svg/profile.svg" alt="avatar">
    </button>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from "vue";
import { useSetupTabs } from "~/composables/setupTabs";
import ProfileModal from "~/components/profile/modal/ProfileModal.vue";

const theme = ref("light");
const { activeTab, setActiveTab } = useSetupTabs();
const config = useRuntimeConfig();
const user = ref()
const displayProfileModal = ref(false)
const setups = ref()
const route = useRoute()
async function fetchUserData() {
  try {
    const res = await useApiRequest(`/user/telegram`);
    user.value = res.data.value.user
    setups.value = res.data.value.setups
  } catch (e) {
    console.error(e);
  }
}
onMounted(async () => {
  await fetchUserData()
});
const availableSetups = computed(() => {
  if (setups.value?.length) {
    return [...setups.value.map((s) => s.id)].sort((a, b) => a - b);
  }
  return Array.from({ length: 10 }, (_, i) => i);
});

const onSetupChange = (event) => {
  setActiveTab(Number(event.target.value));
};

const onClickTab = (tab) => {
  setActiveTab(tab);
};
const logout = () => {
  const router = useRouter();
  document.cookie = "user=;max-age=0";
  router.push('/auth/telegram')
};
const setTheme = (value) => {
  theme.value = value;

  // 1. ставим класс на body
  if (value === "dark") {
    document.body.classList.add("dark");
  } else {
    document.body.classList.remove("dark");
  }

  // 2. сохраняем в куки на 30 дней
  document.cookie = `theme=${value};path=/;max-age=${60 * 60 * 24 * 30}`;
};

const toggleTheme = () => {
  setTheme(theme.value === "light" ? "dark" : "light");
};

const getCookie = (name) => {
  const match = document.cookie.match(new RegExp("(^| )" + name + "=([^;]+)"));
  return match ? match[2] : null;
};

// при загрузке страницы восстанавливаем тему из куки
onMounted(() => {
  const saved = getCookie("theme");
  if (saved) {
    setTheme(saved);
  } else {
    setTheme("light");
  }
});
</script>
<style>
.header__ava {
  margin-left: 20px;
  width: 40px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  flex-shrink: 0;
  background: #DDF0F3;
  border-radius: 4px;
  cursor: pointer;
}
.dark .header__ava {
  background: #0F131C;
}
.dark .header__ava svg {
  filter: brightness(50);
}
.profile-r__modal-cancel,
.profile-r__modal-ok {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 120px;
  height: 40px;
  border: 1px solid #3758F9;
  outline: none;
  border-radius: 6px;
}
.profile-r__modal-cancel {
  color: #3758F9;
}
.profile-r__modal-ok {
  color: #FFFFFF;
  background: #3758F9;
}
.profile-r__modal-btns {
  margin-top: 15px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.profile-r__modal-row input::placeholder {
  font-size: 16px;
}
.profile-r__modal-row input {
  height: 46px;
  width: 100%;
  border: 1px solid #E3E3E3;
  border-radius: 6px;
  padding: 5px 20px;
  box-sizing: border-box;
  color: #2F354E;
  font-size: 16px;
  outline: none;
}
.profile-r__modal-row label {
  margin-bottom: 5px;
  display: block;

  font-size: 18px;
  line-height: 120%;
  color: #2F354E;
}
.profile-r__modal-tt {
  margin-bottom: 15px;

  font-size: 20px;
  color: #2F354E;
  text-transform: uppercase;
  line-height: 120%;
  text-align: center;
}
.profile-r__modal {
  width: 345px;

  position: absolute;
  right: 0;
  top: 35px;
  z-index: 2;

  border-radius: 20px;
  padding: 13px 26px;
  box-sizing: border-box;
  background: #FFFFFF;
  text-align: left;
  box-shadow: 0px 4px 250px 0px rgba(0, 0, 0, 0.15);
}

@media (max-width: 380px) {
  .profile-r__modal-cancel,
  .profile-r__modal-ok {
    width: 110px;
  }
  .profile-r__modal {
    width: 290px;
  }
}

.profile-r__b {
  margin-top: 20px;
  text-align: center;
}
.profile-r__exit svg {
  margin-right: 15px;
}
.profile-r__exit {
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  font-size: 18px;
  color: #D40D0D;
  line-height: 120%;
}
.profile-r__menu-item:hover{
  background: #DDF0F3;
}
.profile-r__menu-item + .profile-r__menu-item {
  margin-top: 22px;
}
.profile-r__menu-item {
  padding: 5px;
  box-sizing: border-box;
  display: block;
  font-size: 18px;
  line-height: 120%;
  color: #2F354E;

  border-radius: 4px;
}
.profile-r__menu {
  text-align: center;
  position: relative;
}
.profile-r__lang-item {
  width: 40px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 3px;
  font-size: 12px;
  line-height: 110%;
  color: #2F354E;
  cursor: pointer;
}
.profile-r__lang-item.active {
  background: #DDF0F3;
}
.profile-r__lang {
  margin-bottom: 5px;
  width: 80px;
  display: flex;
  border-radius: 4px;
  border: 1px solid #E3E3E3;
}
.profile-r__info-name {
  font-weight: 700;
  font-size: 20px;
  line-height: 110%;
  color: #2F354E;
  margin-top: 3px;
}
.profile-r__info-tt {
  font-size: 16px;
  line-height: 100%;
}
.profile-r__info {

}
.profile-r__ava {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  margin-right: 23px;

  position: relative;
  overflow: hidden;

  background: #DDF0F3;
  border-radius: 4px;
}
.profile-r__ava img {
  width: 100%;
  height: 100%;
  display: block;

  position: absolute;
  top: 0;
  left: 0;
  z-index: 1;

  object-fit: cover;
}
.profile-r__close {
  cursor: pointer;

  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 2;
}
.profile-r__top {
  display: flex;
  align-items: center;
  padding-right: 15px;
  box-sizing: border-box;
  margin-bottom: 17px;
}
.profile-r:before {
  content: '';
  width: 100%;
  height: 100%;
  display: block;

  position: fixed;
  top: 0;
  left: 0;
  z-index: 99;

  background: rgba(255,255,255,0.7);
}
.profile-r__inner {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  width: 286px;
  height: calc(100vh - 80px);
  padding: 17px;

  box-sizing: border-box;
  position: fixed;
  top: 40px;
  right: 0;
  z-index: 99;

  box-shadow: 0px 4px 12px 0px rgba(0, 0, 0, 0.15);
  background: #FFFFFF;
  border-radius: 8px 0 0 8px;
}


.dark .profile-r__menu-item {
  color: #E3E3E3;
}
.dark .profile-r__menu-item:hover {
  background: #0F131C;
}
.dark .profile-r__lang-item.active {
  background: #0F131C;
}
.dark .profile-r__lang-item {
  color: #E3E3E3;
}
.dark .profile-r__info-tt {
  color: #C8C8C8;
}
.dark .profile-r__info-name {
  color: #E3E3E3;
}
.dark .profile-r__ava {
  background: #0F131C;
}
.dark .profile-r:before {
  background: rgba(28, 35, 49, 0.7);
}
.dark .profile-r__inner{
  background: #2F354E;
}


.dark .profile-r__modal-cancel {
}
.dark .profile-r__modal-tt,
.dark .profile-r__modal-row label{
  color: #E3E3E3;
}
.dark .profile-r__modal {
  background: #2F354E;
}









.head-top {
  margin: 30px 0 5px;
  padding: 0 40px;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.tbs {
  display: inline-flex;
  position: relative;
}
.tbs:after {
  content: '';
  width: 100%;
  height: 1px;

  position: absolute;
  bottom: 0;
  left: 0;
  z-index: 1;

  background: #E3E3E3;
}
.tbs-item {
  padding: 10px;
  border-bottom: 3px solid transparent;

  box-sizing: border-box;
  font-size: 18px;
  line-height: 110%;
  border-radius: 4px 4px 0 0;
  position: relative;
  z-index: 3;
  transition: 0.3s;
  cursor: pointer;
  margin-right: 5px;
}
.tbs-item.active {
  border-bottom-color: #3758F9;
  background: #DDF0F3;
  color: #3758F9!important;
}
.tbs-item:hover {
  color: #2F354E;
  background: #DDF0F3;
}
.tbs-item.disabled {
  color: #C8C8C8;
  cursor: default;
}
.tbs-item.disabled:hover {
  background: transparent;
}

.dark .tbs-item {
  color: #FFFFFF;
}
.dark .tbs-item.disabled {
  color: rgba(255,255,255,0.5)!important;
}
.dark .tbs-item:hover {
  color: #2F354E;
}

.setup-select-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 0;
}
.setup-select-label {
  font-size: 16px;
  color: #2F354E;
  white-space: nowrap;
}
.setup-select {
  height: 36px;
  min-width: 80px;
  border: 1px solid #E3E3E3;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 16px;
  color: #2F354E;
  background: #FFFFFF;
  outline: none;
  cursor: pointer;
  transition: border-color 0.2s;
}
.setup-select:focus {
  border-color: #3758F9;
}
.dark .setup-select-label { color: #E3E3E3; }
.dark .setup-select {
  background: #2F354E;
  color: #E3E3E3;
  border-color: #5a6278;
}






body.dark {
  background: #1C2331;
  color: #FFFFFF;
}
.dark .signal-page__table table .textarea:before{
  filter: brightness(50);
}
.dark .signal-page__switch-bl input[type='checkbox'] + label {
  background: #2F354E;
  border-color: #E3E3E3;
}
.dark .signal-page__switch-bl input[type='checkbox'] + label span {
  background: #E3E3E3;
}
.dark .signal-page__table table {
  color: #FFFFFF;
}
.dark .signal-page__table table .textarea textarea {
  border-color: #E3E3E3;
  background: #1C2331;
  color: #E3E3E3;
}
.dark {
  border-color: #576177;
}
.dark table td {
  border-color: #E3E3E3;
}
.dark table tbody + tbody {
  border-color: #2F354E;
}

.dark {
  background: #2F354E;
}
.dark {
  color: #E3E3E3;
}
.dark input,
.dark .select select {
  border-color: #E3E3E3;
  color: #E3E3E3;
}
.dark input::placeholder {
  color: #E3E3E3;
}
.dark .btn-cancel {
  border-color: #E3E3E3;
  background: #2F354E;
  color: #E3E3E3;
}
.dark .select select {
  background-image: url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHZpZXdCb3g9IjAgMCAxNiAxNiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTEzLjU4NTYgNC45ODU4NEMxMy42ODA0IDQuODkxMDEgMTMuODE5OCA0Ljg5MTAyIDEzLjkxNDcgNC45ODU4NEMxNC4wMDg3IDUuMDgwMDEgMTQuMDA5NSA1LjIxODI1IDEzLjkxNjYgNS4zMTI5OUw4LjE2NjYzIDEwLjk2MjRMOC4xNjQ2NyAxMC45NjQ0QzguMDY4MzYgMTEuMDYwNyA4LjAyMjc2IDExLjA2NjkgNy45OTk2MyAxMS4wNjY5QzcuOTM5NzkgMTEuMDY2OCA3Ljg4ODY0IDExLjA1MTEgNy44MTcwMiAxMC45OTU2TDIuMDgzNjIgNS4zNjI3OUMxLjk5MDc0IDUuMjY4MDIgMS45OTE0IDUuMTI5ODIgMi4wODU1NyA1LjAzNTY0QzIuMTgwMzkgNC45NDA5MiAyLjMxOTg4IDQuOTQwODUgMi40MTQ2NyA1LjAzNTY0TDIuNDE3NiA1LjAzODU3TDcuNzY3MjEgMTAuMjY0Mkw4LjAwMTU5IDEwLjQ5MjdMOC4yMzQwMSAxMC4yNjIyTDEzLjU4MzYgNC45ODc3OUwxMy41ODU2IDQuOTg1ODRaIiBmaWxsPSIjRTNFM0UzIiBzdHJva2U9IiNFM0UzRTMiIHN0cm9rZS13aWR0aD0iMC42NjY2NjciLz4KPC9zdmc+Cg==');
}
.dark .select select option {
  color: #E3E3E3;
}
.dark .select select option:hover,
.dark .select select option:focus {
  background-color: #3758F9;
  color: #FFFFFF;
}
.dark {
  background: rgba(28, 35, 49, 0.5);
}

.dark {
  box-shadow: 0 10px 10px rgba(61, 91, 239, 0.3);
}
</style>
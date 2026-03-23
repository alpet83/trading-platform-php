<template>
  <div class="p-6">
    <!-- Вкладки -->
    <div class="flex gap-4 mb-6">
      <button
          class="px-4 py-2 rounded"
          :class="activeTab === 'users' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:text-black'"
          @click="activeTab = 'users'"
      >
        Пользователи
      </button>

      <button
          class="px-4 py-2 rounded"
          :class="activeTab === 'bots' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:text-black'"
          @click="activeTab = 'bots'"
      >
        Боты
      </button>
    </div>

    <!-- Контент вкладок -->
    <AdminUsers v-if="activeTab === 'users'" />
    <AdminBots v-else-if="activeTab === 'bots'" />
  </div>
</template>

<script setup>
import { ref } from 'vue'

import AdminUsers from '~/components/admin/AdminUsers.vue'
import AdminBots from "~/components/admin/AdminBots.vue";

const activeTab = ref('users')
</script>
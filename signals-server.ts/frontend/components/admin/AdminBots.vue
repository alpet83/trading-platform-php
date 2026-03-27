<template>
  <BaseTable
      @clickBtn="createBot"
      v-if="bots?.length"
      btn_title="Создать бота"
      :data="bots"
      row-key="applicant"
      :columns-count="16"
      searchable
      :search-keys="['applicant', 'account_id', 'config.exchange']"
      :per-page="5"
  >
    <!-- HEADER -->
    <template #header>
      <th class="px-4 py-3">Bot</th>
      <th class="px-4 py-3">Account ID</th>
      <th class="px-4 py-3">Exchange</th>
      <th class="px-4 py-3">Trade Enabled</th>
      <th class="px-4 py-3">Monitor Enabled</th>
      <th class="px-4 py-3">Position Coef</th>
      <th class="px-4 py-3">Min Order Cost</th>
      <th class="px-4 py-3">Max Order Cost</th>
      <th class="px-4 py-3">Max Limit Distance</th>
      <th class="px-4 py-3">Signals Setup</th>
      <th class="px-4 py-3">Report Color</th>
      <th class="px-4 py-3">Debug Pair</th>
      <th class="px-4 py-3">Max Pos Cost</th>
      <th class="px-4 py-3">Max Pos Amount</th>
      <th class="px-4 py-3">Shorts Mult</th>
      <th class="px-4 py-3 text-right">Actions</th>
    </template>

    <!-- ROW -->
    <template #row="{ row }">
      <td class="px-4 py-2 text-center">{{ row.applicant }}</td>
      <td class="px-4 py-2 text-center">{{ row.account_id }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.exchange }}</td>
      <td class="px-4 py-2 text-center"><StatusIcon :value="row.config.trade_enabled" /></td>
      <td class="px-4 py-2 text-center"><StatusIcon :value="row.config.monitor_enabled" /></td>
      <td class="px-4 py-2 text-center">{{ row.config.position_coef }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.min_order_cost }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.max_order_cost }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.max_limit_distance }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.signals_setup }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.report_color }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.debug_pair }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.max_pos_cost || '-' }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.max_pos_amount || '-' }}</td>
      <td class="px-4 py-2 text-center">{{ row.config.shorts_mult || '-' }}</td>
      <td class="px-4 py-2 text-right flex justify-end gap-2">
        <button @click="editBot(row)" class="text-blue-600 hover:text-blue-800" title="Редактировать">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path :d="mdiPencil" />
          </svg>
        </button>
        <button @click="deleteBot(row)" class="text-red-600 hover:text-red-800" title="Удалить">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path :d="mdiDelete" />
          </svg>
        </button>
      </td>
    </template>
  </BaseTable>

  <ConfirmDialog
      v-if="botToDelete"
      title="Удалить бота?"
      :message="`Вы уверены, что хотите удалить ${botToDelete?.applicant}?`"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
  />

  <EditBotDialog
      v-if="botToEdit"
      :bot="botToEdit"
      @save="saveBot"
      @cancel="cancelEdit"
  />

  <div class="mt-10 space-y-4">
    <h3 class="text-lg font-semibold">Хосты статистики</h3>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
      <div>
        <label class="block text-sm mb-1">Название</label>
        <input
            v-model="hostForm.host_name"
            class="w-full border rounded px-3 py-2 dark:text-black"
            placeholder="main-host"
            type="text"
        />
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm mb-1">Instance URL</label>
        <input
            v-model="hostForm.instance_url"
            class="w-full border rounded px-3 py-2 dark:text-black"
            placeholder="https://example.com"
            type="text"
        />
      </div>
      <label class="inline-flex items-center gap-2 text-sm">
        <input v-model="hostForm.is_active" type="checkbox" />
        Сделать активным
      </label>
    </div>

    <div class="flex gap-2">
      <button
          class="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700"
          type="button"
          @click="saveHost"
      >
        {{ hostForm.host_id ? 'Сохранить хост' : 'Добавить хост' }}
      </button>
      <button
          v-if="hostForm.host_id"
          class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300 dark:text-black"
          type="button"
          @click="resetHostForm"
      >
        Отмена
      </button>
    </div>

    <div class="overflow-x-auto" v-if="hosts.length">
      <table class="min-w-full border border-gray-200 rounded-lg overflow-hidden">
        <thead>
        <tr>
          <th class="px-4 py-3 text-left">ID</th>
          <th class="px-4 py-3 text-left">Название</th>
          <th class="px-4 py-3 text-left">URL</th>
          <th class="px-4 py-3 text-center">Активный</th>
          <th class="px-4 py-3 text-right">Действия</th>
        </tr>
        </thead>
        <tbody>
        <tr v-for="host in hosts" :key="host.host_id" class="border-t">
          <td class="px-4 py-2">{{ host.host_id }}</td>
          <td class="px-4 py-2">{{ host.host_name }}</td>
          <td class="px-4 py-2">{{ host.instance_url }}</td>
          <td class="px-4 py-2 text-center">
            <StatusIcon :value="host.is_active" />
          </td>
          <td class="px-4 py-2 text-right space-x-2">
            <button
                type="button"
                class="text-blue-600 hover:text-blue-800"
                @click="editHost(host)"
            >
              Изменить
            </button>
            <button
                v-if="!host.is_active"
                type="button"
                class="text-green-600 hover:text-green-800"
                @click="activateHost(host.host_id)"
            >
              Активировать
            </button>
            <button
                type="button"
                class="text-red-600 hover:text-red-800"
                @click="deleteHost(host.host_id)"
            >
              Удалить
            </button>
          </td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount } from 'vue'
import BaseTable from './BaseTable.vue'
import { useApiRequest } from '~/composables/api'

import StatusIcon from "~/components/admin/StatusIcon.vue";
import {mdiDelete, mdiPencil} from "@mdi/js";
import ConfirmDialog from "~/components/admin/ConfirmDialog.vue";
import EditBotDialog from "~/components/admin/EditBotDialog.vue";

const bots = ref([])
const hosts = ref([])
const botToDelete = ref(null)
const botToEdit = ref(null)
const hostForm = ref({
  host_id: null,
  host_name: '',
  instance_url: '',
  is_active: false,
})

onBeforeMount(async () => {
  await fetchBots()
  await fetchHosts()
})
function createBot() {
  botToEdit.value = {
    bot_name: '',       // editable
    account_id: '',     // editable
    toCreate: true,
    config: {
      exchange: '',
      trade_enabled: '0',
      position_coef: '',
      monitor_enabled: '0',
      min_order_cost: '',
      max_order_cost: '',
      max_limit_distance: '',
      signals_setup: '',
      report_color: '',
      debug_pair: '',
      // optional editable fields
      api_key_name: '',
      api_secret_name: '',
      api_secret_sep: '',
      api_secret_sep_: '',
      max_pos_cost: '',
      max_pos_amount: '',
      shorts_mult: '',
      last_nonce: '',
      limit_base_ttl: '',
      order_timeout: ''
    }
  }
}

async function fetchBots() {
  const res = await useApiRequest('/api/bots', {
    method: 'GET'
  })
  bots.value = res.data?.value?.bots
}

async function fetchHosts() {
  const res = await useApiRequest('/api/instance/hosts', {
    method: 'GET'
  })
  hosts.value = (res.data?.value || [])
}

function editBot(bot) {
  botToEdit.value = bot
}

function deleteBot(bot) {
  botToDelete.value = bot
}

function cancelEdit() {
  botToEdit.value = null
}

async function saveBot(editedBot) {
  if (editedBot.toCreate) {
    await useApiRequest('/api/bots/create', {
      method: 'POST',
      body: editedBot
    })
  } else {
    await useApiRequest('/api/bots/update', {
      method: 'POST',
      body: editedBot
    })
  }

  botToEdit.value = null
  await fetchBots()
}

async function confirmDelete() {
  console.log(botToDelete.value);
  await useApiRequest(`/api/bots/${botToDelete.value.applicant}`, {
    method: 'DELETE'
  })
  botToDelete.value = null
  await fetchBots()
}

function cancelDelete() {
  botToDelete.value = null
}

function resetHostForm() {
  hostForm.value = {
    host_id: null,
    host_name: '',
    instance_url: '',
    is_active: false,
  }
}

function editHost(host) {
  hostForm.value = {
    host_id: host.host_id,
    host_name: host.host_name,
    instance_url: host.instance_url,
    is_active: Boolean(host.is_active),
  }
}

async function saveHost() {
  if (!hostForm.value.host_name || !hostForm.value.instance_url) {
    return
  }

  const payload = {
    host_name: hostForm.value.host_name,
    instance_url: hostForm.value.instance_url,
    is_active: hostForm.value.is_active,
  }

  if (hostForm.value.host_id) {
    await useApiRequest(`/api/instance/hosts/${hostForm.value.host_id}`, {
      method: 'POST',
      body: payload,
    })
  } else {
    await useApiRequest('/api/instance/hosts', {
      method: 'POST',
      body: payload,
    })
  }

  resetHostForm()
  await fetchHosts()
}

async function activateHost(hostId) {
  await useApiRequest(`/api/instance/hosts/${hostId}/activate`, {
    method: 'POST',
  })
  await fetchHosts()
}

async function deleteHost(hostId) {
  await useApiRequest(`/api/instance/hosts/${hostId}`, {
    method: 'DELETE',
  })
  await fetchHosts()
  if (hostForm.value.host_id === hostId) {
    resetHostForm()
  }
}
</script>
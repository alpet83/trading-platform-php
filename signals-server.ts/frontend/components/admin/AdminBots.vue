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
</template>

<script setup lang="ts">
import { ref, onBeforeMount } from 'vue'
import BaseTable from './BaseTable.vue'
import { useApiRequest } from '~/composables/api'

import StatusIcon from "~/components/admin/StatusIcon.vue";
import {mdiDelete, mdiPencil} from "@mdi/js";
import EditUserDialog from "~/components/admin/EditUserDialog.vue";
import ConfirmDialog from "~/components/admin/ConfirmDialog.vue";
import EditBotDialog from "~/components/admin/EditBotDialog.vue";

const bots = ref([])
const botToDelete = ref(null)
const botToEdit = ref(null)

const roleLabels = {
  admin: 'Администратор',
  view: 'Просмотр',
  trade: 'Торговля'
}
onBeforeMount(async () => {
  await fetchBots()
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
</script>
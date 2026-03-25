<template>
  <BaseTable
      @clickBtn="createUser"
      btn_title="Создать пользователя"
      v-if="users?.value?.length"
      :data="users.value"
      row-key="id"
      :columns-count="6"
      searchable
      :search-keys="['user_name']"
      :per-page="5"
  >
    <template #header>
      <th class="px-4 py-3">ID</th>
      <th class="px-4 py-3">Username</th>
      <th class="px-4 py-3 text-center">Setup Base</th>
      <th class="px-4 py-3">Права доступа</th>
      <th class="px-4 py-3 text-center">Активен</th>
      <th class="px-4 py-3 text-right">Действия</th>
    </template>

    <template #row="{ row }">
      <td class="px-4 py-2 text-center">{{ row.id }}</td>
      <td class="px-4 py-2 font-medium text-center">{{ row.user_name }}</td>
      <td class="px-4 py-2 text-center">{{ row.base_setup ?? 0 }}</td>
      <td class="px-4 py-2 text-center">
        {{ row.rights?.map(r => roleLabels[r] || r).join(', ') || '-' }}
      </td>
      <td class="px-4 py-2 text-center">
        <StatusIcon :value="row.enabled" />
      </td>
      <td class="px-4 py-2 text-right flex justify-end gap-2">
        <!-- Edit -->
        <button
            @click="editUser(row)"
            class="text-blue-600 hover:text-blue-800"
            title="Редактировать"
        >
          <svg
              class="w-5 h-5"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="currentColor"
          >
            <path :d="mdiPencil" />
          </svg>
        </button>

        <!-- Delete -->
        <button
            @click="deleteUser(row)"
            class="text-red-600 hover:text-red-800"
            title="Удалить"
        >
          <svg
              class="w-5 h-5"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="currentColor"
          >
            <path :d="mdiDelete" />
          </svg>
        </button>
      </td>
    </template>
  </BaseTable>
  <ConfirmDialog
      v-if="userToDelete"
      title="Удалить пользователя?"
      :message="`Вы уверены, что хотите удалить ${userToDelete?.user_name}?`"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
  />
  <EditUserDialog
      v-if="userToEdit"
      :user="userToEdit"
      @save="saveUser"
      @cancel="cancelEdit"
  />
</template>

<script setup>
import BaseTable from './BaseTable.vue'
import {useApiRequest} from "~/composables/api";
import { mdiPencil, mdiDelete } from '@mdi/js'
import ConfirmDialog from "~/components/admin/ConfirmDialog.vue";
import EditUserDialog from "~/components/admin/EditUserDialog.vue";
import StatusIcon from "~/components/admin/StatusIcon.vue";
const userToDelete = ref(null)
const userToEdit = ref(null)
function createUser() {
  userToEdit.value = { id: '', user_name: '', rights: [], enabled: true, toCreate: true }
}
const roleLabels = {
  admin: 'Администратор',
  view: 'Просмотр',
  trade: 'Торговля'
}
const users = ref([])
async function fetchUsers() {
  const res = await useApiRequest(`/api/external/user`, {
    method: "GET"
  });
  users.value = res.data;
}
function deleteUser(user) {
  console.log(user);
  userToDelete.value = user
}
function editUser(user) {
  console.log(user);
  userToEdit.value = user
}
function cancelEdit() {
  userToEdit.value = null
}
async function saveUser(editedUser) {
  if (editedUser.toCreate) {
    await useApiRequest(`/api/external/user`, {
      method: "POST",
      body: editedUser
    });
  } else {
    await useApiRequest(`/api/external/user/update`, {
      method: "POST",
      body: editedUser
    });
  }
  userToEdit.value = null
  await fetchUsers()
}
async function confirmDelete() {
  console.log('Удаляем пользователя:', userToDelete.value)
  await useApiRequest(`/api/external/user/${userToDelete.value.id}`, {
    method: "DELETE"
  });
  userToDelete.value = null
  await fetchUsers()
}

function cancelDelete() {
  userToDelete.value = null
}

onBeforeMount(async () => {
  await fetchUsers()
})
</script>
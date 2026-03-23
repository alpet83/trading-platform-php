<template>
  <BaseTable
      :data="users"
      row-key="id"
      :columns-count="4"
      searchable
      :search-keys="['name', 'email']"
      paginated
      :per-page="5"
  >
    <template #header>
      <th class="px-4 py-3">ID</th>
      <th class="px-4 py-3">Имя</th>
      <th class="px-4 py-3">Email</th>
      <th class="px-4 py-3 text-right">Действия</th>
    </template>

    <template #row="{ row }">
      <td class="px-4 py-2">{{ row.id }}</td>
      <td class="px-4 py-2 font-medium">{{ row.name }}</td>
      <td class="px-4 py-2 text-gray-600">{{ row.email }}</td>
      <td class="px-4 py-2 text-right">
        <button class="text-blue-600 hover:underline">Редактировать</button>
      </td>
    </template>
  </BaseTable>
</template>

<script setup>
import BaseTable from './BaseTable.vue'
import {useApiRequest} from "~/composables/api";

const signals = ref([])

onBeforeMount(async () => {
  await fetchSignals()
})
async function fetchSignals() {
  const res = await useApiRequest(`/api/external/signals`, {
    method: "GET"
  });
  signals.value = res.data;
}
</script>
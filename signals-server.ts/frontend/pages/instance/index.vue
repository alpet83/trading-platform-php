<template>
  <div class="admin">
    <NuxtLink to="/" class="prev">
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M5.78033 9.96967C6.07322 10.2626 6.07322 10.7374 5.78033 11.0303L3.31066 13.5L5.78033 15.9697C6.07322 16.2626 6.07322 16.7374 5.78033 17.0303C5.48744 17.3232 5.01256 17.3232 4.71967 17.0303L1.71967 14.0303C1.42678 13.7374 1.42678 13.2626 1.71967 12.9697L4.71967 9.96967C5.01256 9.67678 5.48744 9.67678 5.78033 9.96967Z" fill="#3758F9"/>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M21.75 6.75C22.1642 6.75 22.5 7.08579 22.5 7.5V8.4375C22.5 11.5831 19.9683 14.25 16.7812 14.25H3C2.58579 14.25 2.25 13.9142 2.25 13.5C2.25 13.0858 2.58579 12.75 3 12.75H16.7812C19.1029 12.75 21 10.7922 21 8.4375V7.5C21 7.08579 21.3358 6.75 21.75 6.75Z" fill="#3758F9"/>
      </svg>
      Signals
    </NuxtLink>
    <div class="mb-4 max-w-md">
        <label for="instance-host" class="block text-sm mb-1">Instance host</label>
      <select
          id="instance-host"
          v-model="selectedHostId"
          class="w-full rounded border border-gray-300 px-3 py-2 dark:text-black"
      >
        <option value="">Active host</option>
        <option v-for="host in hosts" :key="host.host_id" :value="String(host.host_id)">
          {{ host.host_name }} ({{ host.instance_url }})
        </option>
      </select>
    </div>
    <InstanceTable :data="signalsTableInfo" :host-id="selectedHostId"></InstanceTable>
    <RiskTable :shortVolume="shortVolume" :longVolume="longVolume" :data="riskTableInfo"></RiskTable>
  </div>
</template>
<script setup lang="ts">
import {onMounted, watch} from "vue";
import RiskTable from "~/components/instance/RiskTable.vue";
import InstanceTable from "~/components/instance/InstanceTable.vue";

definePageMeta({
  layout: 'default',
});

const longVolume = ref(0)
const shortVolume = ref(0)
const signalsTableInfo = ref();
const riskTableInfo = ref();
const hosts = ref<Array<{ host_id: number; host_name: string; instance_url: string }>>([])
const route = useRoute()
const router = useRouter()
const selectedHostId = ref(String(route.query.hostId || ''))

onMounted(async () => {
  await fetchHosts()
  await fetchData()
});

watch(selectedHostId, async (value) => {
  const query = { ...route.query }
  if (value) {
    query.hostId = value
  } else {
    delete query.hostId
  }
  await router.replace({ path: '/instance', query })
  await fetchData()
})

async function fetchHosts() {
  const res = await useApiRequest('/api/instance/hosts', {
    method: 'GET'
  })
  hosts.value = (res.data.value || []) as Array<{ host_id: number; host_name: string; instance_url: string }>
}

async function fetchData() {
  const res = await useApiRequest(`/api/instance/mainTable`, {
    method: "GET",
    params: selectedHostId.value ? { hostId: selectedHostId.value } : undefined,
  });
  const responseData = res.data.value;
  longVolume.value = responseData?.volumes?.long
  shortVolume.value = responseData?.volumes?.short
  signalsTableInfo.value = responseData.bots
  riskTableInfo.value = responseData.risk_mapping
}
</script>

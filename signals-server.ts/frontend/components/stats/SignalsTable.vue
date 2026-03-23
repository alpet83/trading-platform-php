<template>
  <div class="admin__table">
    <table>
      <thead>
      <tr>
        <th v-for="(header, index) in headers" :key="header.key">
          {{ header.title }}
        </th>
      </tr>
      </thead>
      <tbody>
      <tr :class="`bg${index+1}`" v-for="(item, index) in data" :key="item.pid">
        <td>{{ item.bot }}</td>
        <td><nuxt-link style="color: black; text-decoration: underline;" :to="{
        path: '/stats/account',
        query: {
          bot: item.bot,
          account: item.account,
          exchange: item.exchange
        }
    }">{{ item.account }}</nuxt-link></td>
        <td>{{ item.started }}</td>
        <td>{{ item.last_alive }}</td>
        <td>{{ item.matched_orders }}</td>
        <td>{{ item.funds_usage }}</td>
        <td>{{ item.restarts }}</td>
        <td>{{ item.exceptions }}</td>
        <td><button class="bt-er" @click="goToError(item)">
          {{ item.errors }}
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
            <g clip-path="url(#clip0_327_2724)">
              <path d="M0 8C0 10.1217 0.842855 12.1566 2.34315 13.6569C3.84344 15.1571 5.87827 16 8 16C10.1217 16 12.1566 15.1571 13.6569 13.6569C15.1571 12.1566 16 10.1217 16 8C16 5.87827 15.1571 3.84344 13.6569 2.34315C12.1566 0.842855 10.1217 0 8 0C5.87827 0 3.84344 0.842855 2.34315 2.34315C0.842855 3.84344 0 5.87827 0 8H0ZM5.904 10.803C5.85788 10.8508 5.8027 10.8888 5.7417 10.9151C5.6807 10.9413 5.61509 10.955 5.5487 10.9556C5.48231 10.9562 5.41647 10.9436 5.35502 10.9184C5.29357 10.8933 5.23775 10.8561 5.1908 10.8092C5.14386 10.7623 5.10673 10.7064 5.08159 10.645C5.05645 10.5835 5.0438 10.5177 5.04437 10.4513C5.04495 10.3849 5.05874 10.3193 5.08495 10.2583C5.11115 10.1973 5.14924 10.1421 5.197 10.096L9.293 6H6.525C6.39239 6 6.26521 5.94732 6.17145 5.85355C6.07768 5.75979 6.025 5.63261 6.025 5.5C6.025 5.36739 6.07768 5.24021 6.17145 5.14645C6.26521 5.05268 6.39239 5 6.525 5H10.5C10.6326 5 10.7598 5.05268 10.8536 5.14645C10.9473 5.24021 11 5.36739 11 5.5V9.475C11 9.60761 10.9473 9.73479 10.8536 9.82855C10.7598 9.92232 10.6326 9.975 10.5 9.975C10.3674 9.975 10.2402 9.92232 10.1464 9.82855C10.0527 9.73479 10 9.60761 10 9.475V6.707L5.904 10.803Z" fill="#3758F9"/>
            </g>
            <defs>
              <clipPath id="clip0_327_2724">
                <rect width="16" height="16" fill="white"/>
              </clipPath>
            </defs>
          </svg>
        </button>
        </td>
        <td><div class="error-box" v-html="parseColoredMessage(item.last_error_raw)"></div></td>
        <td>{{ item.pid }}</td>
        <td class="position-cell">
          <div
              v-if="editingRow !== item.pid"
              class="position-text"
              @click="startEditing(item)"
          >
            {{ item.position_coef }}
          </div>
          <input
              v-else
              type="number"
              step="0.01"
              v-model.number="item.position_coef"
              @blur="finishEditing(item)"
              @keyup.enter="finishEditing(item)"
              class="position-input"
              autofocus
          />
        </td>
        <td>
          <label class="ch">
            <input @change="switchTradeCheckbox(item)" type="checkbox" v-model="item.trade_enabled">
            <span></span>
          </label>
        </td>
      </tr>
      </tbody>
    </table>
  </div>
</template>
<script setup lang="ts">
import {useApiRequest} from "~/composables/api";

const props = defineProps({
  data: {}
})
const editingRow = ref<number | null>(null);
const headers = ref([
  { key: 'bot', title: 'Bot' },
  { key: 'account', title: 'Account' },
  { key: 'started', title: 'Started' },
  { key: 'lastAlive', title: 'Last alive' },
  { key: 'matchedOrders', title: 'Matched orders' },
  { key: 'fundsUsage', title: 'Funds usage' },
  { key: 'restarts', title: 'Restarts' },
  { key: 'exceptions', title: 'Exceptions' },
  { key: 'errors', title: 'Errors' },
  { key: 'lastErrors', title: 'Last errors' },
  { key: 'pid', title: 'PID' },
  { key: 'positionCoef', title: 'Position coef' },
  { key: 'enabled', title: 'Enabled' },
]);
async function switchTradeCheckbox(item) {
  const res = await useApiRequest(`/api/stats/updateTradeEnabled`, {
    method: "POST",
    body: {
      bot: item.bot,
      trade_enabled: item.trade_enabled
    }
  });
}
function startEditing(item: any) {
  editingRow.value = item.pid;
}
function finishEditing(item: any) {
  editingRow.value = null;
  onPositionCoefChange(item);
}
function parseColoredMessage(messageRaw) {
  if (!messageRaw) return '';

  return messageRaw
      // заменяем ~Cxx на открывающий span с классом clxx
      .replace(/~C(\d{2})/g, (_, code) => {
        if (code === '00') return '</span>'; // ~C00 закрывает цвет
        return `<span class="cl${code}">`;
      });
}
async function onPositionCoefChange(item: any) {
  const res = await useApiRequest(`/api/stats/updatePositionCoef`, {
    method: "POST",
    body: {
      bot: item.bot,
      position_coef: item.position_coef
    }
  });
}
function goToError(item: any) {
  const router = useRouter();
  if (!item?.account) return; // проверим на всякий случай
  router.push({
    path: '/stats/error',
    query: { bot: item.bot }
  });
}
</script>
<style>
.position-cell {
  position: relative;
  width: 90px;
  height: 32px;
  text-align: center;
}
.position-text {
  text-decoration: underline;
}
.position-text,
.position-input {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 90%;
  height: 24px;
  box-sizing: border-box;
  text-align: center;
}
.position-input {
  border: 1px solid #ccc;
  border-radius: 6px;
}
.position-text {
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.position-input {
  padding: 4px;
  border: 1px solid #ccc;
  border-radius: 6px;
  text-align: center;
}
.bt-er {
  white-space: nowrap;
  display: inline-flex;
  align-items: center;

  border-radius: 4px;
  padding: 1px 7px;
  box-sizing: border-box;
  background: #FFFFFF;
  cursor: pointer;
  border: none;
}
.error-box {
  max-height: 100px; /* или любое нужное значение */
  overflow-y: auto;
  overflow-x: hidden;
  white-space: pre-wrap; /* чтобы сохранялись переносы строк */
  word-break: break-word; /* чтобы длинные слова не ломали таблицу */
  padding: 4px;
}
.dark .bt-er {
  background: #0F131C;
  color: #FFFFFF;
}
.bt-er svg {
  margin-left: 5px;
}
.prev {
  display: inline-flex;
  align-items: center;
  padding-bottom: 5px;
  box-sizing: border-box;

  position: absolute;
  top: -30px;
  left: 20px;
  z-index: 3;

  font-size: 20px;
  line-height: 110%;
  text-transform: uppercase;
  color: #3758F9;
  border-bottom: 1px solid #3758F9;
}
.prev svg {
  margin-right: 10px;
}
.admin {
  padding: 0 20px 20px;
  box-sizing: border-box;

  position: relative;
}
.admin__table {
  overflow: auto;
}
.admin__table table {
  width: 100%;
}
.admin__table table tr th {
  padding: 18px 5px;
  box-sizing: border-box;
  font-size: 16px;
  font-weight: 400;
  color: #2F354E;
}
.admin__table table tr td {
  padding: 6px 10px;
  box-sizing: border-box;
  font-size: 18px;
  font-weight: 400;
  color: #2F354E;
  text-align: center;
  border-top: 2px solid #F9FEFF;
}
.admin__table table tr td .tx {
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
  line-height: 110%;
}
.admin__table table tr td:nth-of-type(2n+1) {
  background: rgba(255,255,255,0.3);
}
.admin__table table tr.bg1 {
  background: #F5E5D5;
}
.admin__table table tr.bg2 {
  background: #DFD6EE;
}
.admin__table table tr.bg3 {
  background: #BDE1E8;
}
.admin__table table tr.bg4 {
  background: #D3E9B4;
}
.admin__table table tr.bg5 {
  background: #C4EBD6;
}
.ch {
  position: relative;
}
.ch input[type="checkbox"] {
  width: 1px;
  height: 1px;
  position: absolute;
  top: 0;
  background: transparent;
}
.ch input[type="checkbox"] + span {
  width: 20px;
  height: 20px;
  display: block;
  margin: auto;

  border-radius: 4px;
  background-color: #FFFFFF;
  cursor: pointer;
}
.ch input[type="checkbox"]:checked + span {
  background-color: #3758F9;
  background-image: url("data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTEiIGhlaWdodD0iOCIgdmlld0JveD0iMCAwIDExIDgiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxwYXRoIGQ9Ik05LjQyNzczIDAuNjkxNDA2QzkuNTg2MDggMC41Mjc0MjEgOS44MjkzMSAwLjUwNTkzNiAxMC4wMDg4IDAuNjI4OTA2TDEwLjA4MTEgMC42OTA0M0wxMC4wODY5IDAuNjk2Mjg5TDEwLjA5MTggMC43MDIxNDhDMTAuMjQxNCAwLjg4MzE3OCAxMC4yNDI2IDEuMTY2MTYgMTAuMDQ4OCAxLjM0ODYzTDEwLjA0OTggMS4zNDk2MUw0LjcxMzg3IDYuNzA2MDVMNC43MTQ4NCA2LjcwNzAzQzQuNTYwMjkgNi44NjcwNCA0LjM2MjU1IDYuOTUwMTYgNC4xNDc0NiA2Ljk1MDJDMy45NDk1NCA2Ljk1MDIgMy43MzU1IDYuODY5IDMuNTc5MSA2LjcwNzAzVjYuNzA2MDVMMC45MzQ1NyA0LjA0Mzk1TDAuOTMyNjE3IDQuMDQxOTlDMC43NTU3MTMgMy44NTg1MSAwLjc1NTU5OSAzLjU2OTE1IDAuOTMyNjE3IDMuMzg1NzRDMS4xMTI5MSAzLjE5OTA0IDEuNDAyODYgMy4xOTg1MiAxLjU4Mzk4IDMuMzgzNzlMNC4xNjIxMSA1Ljk3OTQ5TDkuNDI3NzMgMC42OTE0MDZaIiBmaWxsPSJ3aGl0ZSIgc3Ryb2tlPSJ3aGl0ZSIgc3Ryb2tlLXdpZHRoPSIwLjQiLz4KPC9zdmc+Cg==");
  background-repeat: no-repeat;
  background-position: center;
}


.dark .prev {
  filter: brightness(50);
}
.dark .admin__table table tr th,
.dark .admin__table table tr td {
  color: #E3E3E3;
}
.dark .admin__table table tr.bg1 {
  background: #5C370C;
}
.dark .admin__table table tr.bg2 {
  background: #3C1A74;
}
.dark .admin__table table tr.bg3 {
  background: #0A5056;
}
.dark .admin__table table tr.bg4 {
  background: #334B0C;
}
.dark .admin__table table tr.bg5 {
  background: #2E514C;
}
.dark .admin__table table tr td {
  border-top: 1px solid #C8C8C8;
  border-bottom: 1px solid #C8C8C8;
}
.dark .admin__table table tr td:first-of-type {
  border-left: 1px solid #C8C8C8;
}
.dark .admin__table table tr td:last-of-type {
  border-right: 1px solid #C8C8C8;
}
.dark .admin__risk-info-item {
  background: #0F131C;
}
.dark .admin__risk-info-item.bg {
  background: #324FDD;
}
.dark .admin__risk-table tr td.bg1 {
  background: #5C370C;
}
.dark .admin__risk-table tr td.bg2 {
  background: #3C1A74;
}
.dark .admin__risk-table tr td.bg3 {
  background: #0A5056;
}
.dark .admin__risk-table tr td.bg4 {
  background: #334B0C;
}
.dark .admin__risk-table tr td.bg5 {
  background: #2E514C;
}
.dark .admin__risk-table tr td {
  border-right: 1px solid #C8C8C8;
}
.dark .admin__risk-table tr:first-of-type td {
  border-top: 1px solid #C8C8C8;
}
.dark .admin__risk-table tr:last-of-type td {
  border-bottom: 1px solid #C8C8C8;
}
.dark .admin__risk-table tr:first-of-type td:first-of-type,
.dark .admin__risk-table tr:last-of-type td:first-of-type {
  border-top: none!important;
  border-bottom: none!important;
}
.dark .admin__table table tr td:nth-of-type(2n+1) {
  background: rgba(255,255,255,0.1);
}
.dark .admin__risk-table tr:nth-of-type(2n) td {
  filter: contrast(1.3);
}

@media (max-width: 400px) {
  .prev {
    margin: 20px 0 10px;
    top: 0;

    left: 0;
    position: relative;
  }
  .admin__risk-info {
    display: block;
  }
  .admin__risk-info-item {
    margin-right: 0;
  }
  .admin__risk-info-item + .admin__risk-info-item {
    margin-top: 10px;
  }
}
</style>
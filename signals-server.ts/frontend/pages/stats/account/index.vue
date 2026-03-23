<template>
  <div class="bot">
    <a href="/stats" class="prev">
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M5.78033 9.96967C6.07322 10.2626 6.07322 10.7374 5.78033 11.0303L3.31066 13.5L5.78033 15.9697C6.07322 16.2626 6.07322 16.7374 5.78033 17.0303C5.48744 17.3232 5.01256 17.3232 4.71967 17.0303L1.71967 14.0303C1.42678 13.7374 1.42678 13.2626 1.71967 12.9697L4.71967 9.96967C5.01256 9.67678 5.48744 9.67678 5.78033 9.96967Z" fill="#3758F9"/>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M21.75 6.75C22.1642 6.75 22.5 7.08579 22.5 7.5V8.4375C22.5 11.5831 19.9683 14.25 16.7812 14.25H3C2.58579 14.25 2.25 13.9142 2.25 13.5C2.25 13.0858 2.58579 12.75 3 12.75H16.7812C19.1029 12.75 21 10.7922 21 8.4375V7.5C21 7.08579 21.3358 6.75 21.75 6.75Z" fill="#3758F9"/>
      </svg>
      Bot statistics
    </a>
    <div class="bot__btn">
      <span>{{ bot }}</span>
      <span>{{ account }}</span>
    </div>

    <h2>Position offset configuration</h2>
    <div class="bot__table">
      <table>
        <thead>
        <tr>
          <th v-for="header in headersPosition" :key="header.key">{{ header.title }}</th>
        </tr>
        </thead>
        <tbody>
        <tr v-for="item in positionOffset" :key="item.time">
          <td>{{ item.ts }}</td>
          <td>{{ item.pair }}</td>
          <td>{{ roundPrice(item.current, item.qty_precision) }}</td>
          <td>{{ roundPrice(item.target, item.qty_precision) }}</td>
          <td>{{ roundPrice(item.last_price, item.price_precision) }}</td>
          <td>{{ item.rpnl }}</td>
          <td>{{ item.upnl }}</td>
          <td class="position-cell w-[200px]">
            <div
                v-if="editingRow !== item.pair_id"
                class="position-text"
                @click="startEditing(item)"
            >
              {{ roundPrice(item.offset, item.price_precision) }}
            </div>
            <input
                v-else
                type="number"
                step="0.01"
                v-model.number="item.offset"
                @blur="finishEditing(item)"
                @keyup.enter="finishEditing(item)"
                class="position-input"
                autofocus
            />
          </td>
        </tr>
        </tbody>
      </table>
    </div>

    <h2 v-if="activeOrders.length > 0">active orders for account <span class="nb">{{ activeOrders.length }}</span> </h2>
    <div v-if="activeOrders.length > 0" class="bot__table">
      <table>
        <thead>
        <tr>
          <th v-for="header in headersActiveOrders" :key="header.key">{{ header.title }}</th>
        </tr>
        </thead>
        <tbody>
        <tr v-for="item in activeOrders" :key="item.batch">
          <td v-html="item.ts.replace(' ', '<br>')"></td>
          <td>{{ item.host_id }}</td>
          <td>{{ item.batch_id }}</td>
          <td>{{ item.signal_id }}</td>
          <td>{{ item.pair }}</td>
          <td>{{ item.price }}</td>
          <td>{{ item.amount }}</td>
          <td>{{ item.matched }}</td>
          <td class="comm">{{ item.comment }}</td>
          <td>
            <div class="inp">
              <button @click="cancelOrder(item)" type="button">CANCEL</button>
            </div>
          </td>
        </tr>
        </tbody>
      </table>
    </div>
    <h2 v-if="limitOrders.length > 0">limit orders for account <span class="nb">{{ limitOrders.length }}</span> </h2>
    <div v-if="limitOrders.length > 0" class="bot__table">
      <table>
        <thead>
        <tr>
          <th v-for="header in headersLimitOrders" :key="header.key">{{ header.title }}</th>
        </tr>
        </thead>
        <tbody>
        <tr v-for="item in limitOrders" :key="item.batch">
          <td v-html="item.ts.replace(' ', '<br>')"></td>
          <td>{{ item.host_id }}</td>
          <td>{{ item.batch_id }}</td>
          <td>{{ item.signal_id }}</td>
          <td>{{ item.pair }}</td>
          <td>{{ item.price }}</td>
          <td>{{ item.amount }}</td>
          <td>{{ item.matched }}</td>
          <td class="comm">{{ item.comment }}</td>
        </tr>
        </tbody>
      </table>
    </div>
    <div style="margin-top: 50px; margin-left: 100px; margin-bottom: 200px">
      <Chart/>
    </div>
  </div>
</template>
<script setup lang="ts">
import {defineComponent, onMounted} from "vue";
import Chart from "~/pages/chart.vue";
import {useApiRequest} from "~/composables/api";

definePageMeta({
  layout: 'default'
});
onMounted(async () => {
  await fetchData()
});
const route = useRoute();
const activeOrders = ref([])
const limitOrders = ref([])
const positionOffset = ref([])
const botInfo = ref({})
const { account, bot, exchange } = route.query;
const editingRow = ref<number | null>(null);
function startEditing(item) {
  editingRow.value = item.pair_id;
}
function roundPrice(target, round) {
  return Number.parseFloat(target).toFixed(round);
}
async function cancelOrder(item) {
  const res = await useApiRequest(`/api/stats/cancelOrder`, {
    method: "POST",
    body: {
      bot: bot,
      order_id: item.id
    }
  });
  window.location.reload()
}
function finishEditing(item) {
  editingRow.value = null;
  changeOffset(item);
}
async function changeOffset(item: any) {
  const res = await useApiRequest(`/api/stats/updateOffset`, {
    method: "POST",
    body: {
      exchange: exchange,
      account: account,
      pair_id: item.pair_id,
      offset: item.offset,
    }
  });
}
async function fetchData() {

  const query = new URLSearchParams({
    account,
    bot,
    exchange
  }).toString();

  const res = await useApiRequest(`/api/stats/account?${query}`, {
    method: "GET"
  });
  const responseData = res.data.value;
  activeOrders.value = responseData.active_orders
  limitOrders.value = responseData.limit_orders
  positionOffset.value = responseData.positions
  botInfo.value = responseData.bot
}
const headersLimitOrders = ref([
  { key: 'time', title: 'Time' },
  { key: 'host', title: 'Host' },
  { key: 'batch', title: 'Batch' },
  { key: 'signal', title: 'Signal' },
  { key: 'pair', title: 'Pair' },
  { key: 'price', title: 'Price' },
  { key: 'amount', title: 'Amount' },
  { key: 'matched', title: 'Matched' },
  { key: 'comment', title: 'Comment' },
]);
const headersPosition = ref([
  { key: 'time', title: 'Time' },
  { key: 'pair', title: 'Pair' },
  { key: 'Current', title: 'Current' },
  { key: 'Target', title: 'Target' },
  { key: 'price', title: 'Last price' },
  { key: 'RPnL', title: 'RPnL' },
  { key: 'UPnL', title: 'UPnL' },
  { key: 'offset', title: 'Offset' },
])
const headersActiveOrders = ref([
    { key: 'time', title: 'Time' },
    { key: 'host', title: 'Host' },
    { key: 'batch', title: 'Batch' },
    { key: 'signal', title: 'Signal' },
    { key: 'pair', title: 'Pair' },
    { key: 'price', title: 'Price' },
    { key: 'amount', title: 'Amount' },
    { key: 'matched', title: 'Matched' },
    { key: 'comment', title: 'Comment' },
    { key: 'action', title: 'Action' },
])
</script>
<style scoped>
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
.position-input {
  width: 100%;
  height: 24px;
  box-sizing: border-box;
  text-align: center;
}
.nb {

  margin-left: 10px;
  display: inline-block;
  text-align: center;
  font-size: 24px;
  line-height: 110%;
  color: #FFFFFF;
  min-width: 34px;
  border-radius: 8px;
  background: #3758F9;
  padding: 5px;
  box-sizing: border-box;
}
.bot__table tr th {
  font-weight: 400;
  font-size: 16px;
  padding: 10px 15px;
  box-sizing: border-box;
}
.bot__table tr td {
  padding: 5px 15px;
  font-size: 18px;
  background: #DDF0F3;
  box-sizing: border-box;
  border-top: 2px solid #F9FEFF;
}
.bot__table tr td:nth-of-type(2n+1) {
  filter: opacity(0.7);
}
.bot__table tr td.comm {

}
.bot__table .inp button {
  background: #3758F9;
  border-radius: 10px;
  font-size: 16px;
  line-height: 100%;
  padding: 5px 15px;
  box-sizing: border-box;
  color: #FFFFFF;
}
.bot__table .inp input + button {
  margin-left: 5px;
}
.bot__table .inp input {
  max-width: 140px;
  width: 100%;
  padding: 0 10px;
  box-sizing: border-box;
  background: #FFFFFF;
  border-radius: 10px;
  border: 1px solid #E3E3E3;
  flex-shrink: 0;
}
.bot__table table {
  text-align: center;
}
.bot__table .inp {
  display: flex;
  align-items: center;
}
.bot__table {
  overflow: auto;
}
.bot h2 {
  display: flex;
  align-items: center;
  margin: 55px 0 20px;

  font-size: 24px;
  line-height: 110%;
  text-transform: uppercase;
  font-weight: 400;
}
.bot__btn span + span {
  margin-left: 10px;
}
.bot__btn {
  margin-top: 20px;
  display: inline-flex;
  align-items: center;
  padding: 13px 16px;
  box-sizing: border-box;

  font-weight: 700;
  font-size: 20px;
  line-height: 120%;

  background: #3758F9;
  border-radius: 10px;
  color: #FFFFFF;
  text-transform: uppercase;
}
.bot__table {

}
.bot {
  padding: 0 20px 20px;
  box-sizing: border-box;

  position: relative;
}

.dark .bot__table tr td {
  background: #0F131C;
}
.dark .bot__table .inp input {
  border-color: #E3E3E3;
}
.dark .bot__table tr td {
  border-top: 1px solid #576177;
}
.dark .bot__table tr:last-of-type td {
  border-bottom: 1px solid #576177;
}
.dark .bot__table tr td:first-of-type {
  border-left: 1px solid #576177;
}
.dark .bot__table tr td:last-of-type {
  border-right: 1px solid #576177;
}
.dark .bot__table tr td:nth-of-type(2n+1) {
  filter: contrast(0.9);
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
.dark .prev {
  filter: brightness(50);
}

@media (max-width: 400px) {
  .prev {
    margin: 20px 0 10px;
    top: 0;

    left: 0;
    position: relative;
  }
}

</style>

<template>
  <div class="er-signal">
    <a href="/stats" class="prev">
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M5.78033 9.96967C6.07322 10.2626 6.07322 10.7374 5.78033 11.0303L3.31066 13.5L5.78033 15.9697C6.07322 16.2626 6.07322 16.7374 5.78033 17.0303C5.48744 17.3232 5.01256 17.3232 4.71967 17.0303L1.71967 14.0303C1.42678 13.7374 1.42678 13.2626 1.71967 12.9697L4.71967 9.96967C5.01256 9.67678 5.48744 9.67678 5.78033 9.96967Z" fill="#3758F9"/>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M21.75 6.75C22.1642 6.75 22.5 7.08579 22.5 7.5V8.4375C22.5 11.5831 19.9683 14.25 16.7812 14.25H3C2.58579 14.25 2.25 13.9142 2.25 13.5C2.25 13.0858 2.58579 12.75 3 12.75H16.7812C19.1029 12.75 21 10.7922 21 8.4375V7.5C21 7.08579 21.3358 6.75 21.75 6.75Z" fill="#3758F9"/>
      </svg>
      Bot statistics
    </a>

    <button class="btn-blue">{{ error.bot }} <span>{{error.account_id}}</span></button>

    <h2>Error</h2>
    <div class="er-signal__table">
      <table>
        <thead>
        <tr>
          <th>Time</th>
          <th>Host</th>
          <th>Code</th>
          <th>Message</th>
          <th>Source</th>
        </tr>
        </thead>
        <tbody>
        <tr v-for="(e, i) in error.errors" :key="i">
          <td>{{ e.time }}</td>
          <td>{{ e.host }}</td>
          <td>{{ e.code }}</td>
          <td><div v-html="parseColoredMessage(e.message_raw)"></div></td>
          <td>{{ e.source }}</td>
        </tr>
        </tbody>
      </table>
    </div>


  </div>

</template>
<script setup>
import {onMounted} from "vue";
const route = useRoute();
const error = ref({});
onMounted(async () => {
  await fetchData()
});
async function fetchData() {
  const res = await useApiRequest(`/api/stats/error/${route.query.bot}`, {
    method: "GET"
  });
  error.value = res.data.value;
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

</script>
<style scoped>
.er-signal__table table {

}
.er-signal__table table th,
.er-signal__table table td {
  padding: 10px 20px;
  box-sizing: border-box;
  font-size: 18px;
  font-weight: 400;
  line-height: 120%;
  text-align: center;
}
.er-signal__table table th {
  padding: 20px 10px;

}
.er-signal__table table td {
  background: #DDF0F3;
  border-top: 2px solid #FFFFFF;
}
.er-signal__table table td .rd {
  color: #DB1717;
}

.er-signal__table td:nth-of-type(2n+1) {
  filter: opacity(0.7);
}

.er-signal {
  padding: 50px 20px 20px;
  position: relative;

  box-sizing: border-box;
}
.er-signal h2 {
  margin: 55px 0 11px;

  font-size: 24px;
  font-weight: 400;
  line-height: 120%;
  text-transform: uppercase;
}

.prev {
  display: inline-flex;
  align-items: center;
  padding-bottom: 5px;
  box-sizing: border-box;

  position: absolute;
  top: 0;
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

.btn-blue {
  padding: 14px;
  box-sizing: border-box;
  display: inline-flex;
  align-items: center;

  background: #3758F9;
  border-radius: 10px;
  box-sizing: border-box;
  font-size: 20px;
  line-height: 120%;
  color: #FFFFFF;
  font-weight: 700;
  text-transform: uppercase;
}
.btn-blue span {
  margin-left: 10px;
}

.dark .er-signal__table table td {
  background: #0F131C;
}
.dark .er-signal__table td:nth-of-type(2n+1) {
  background: rgba(255,255,255,0.1);
}

</style>
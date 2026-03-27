<!-- RiskTable.vue
     Таблица risk-mapping: отображает суммарные позиции (long/short volume)
     и разбивку по биржевым ботам для каждого торгового символа.
     Используется на странице /instance.
-->
<template>
  <div class="admin__risk">
    <h2>Risk mapping</h2>

    <div class="admin__risk-info">
      <div class="admin__risk-info-item bg">
        <div class="admin__risk-info-tt">Long volume</div>
        <div class="admin__risk-info-numb">${{longVolume}}</div>
      </div>
      <div class="admin__risk-info-item">
        <div class="admin__risk-info-tt">Short volume</div>
        <div class="admin__risk-info-numb">${{shortVolume}}</div>
      </div>
    </div>

    <div class="admin__risk-table">
      <table>
        <thead>
        <tr>
          <td>Pair / Symbol</td>
          <td class="bg1">binance_bot</td>
          <td class="bg2">bitfinex_bot</td>
          <td class="bg3">bimex2_bot</td>
          <td class="bg4">bimex_bot</td>
          <td class="bg5">deribit_bot</td>
          <td class="blue">Summary</td>
          <td class="blue">Volume</td>
        </tr>
        </thead>
        <tbody>
        <tr v-for="(item, i) in data" :key="i">
          <td>{{ item.symbol }}</td>
          <td class="bg1">{{ item.positions.binance_bot }}</td>
          <td class="bg2">{{ item.positions.bitfinex_bot }}</td>
          <td class="bg3">{{ item.positions.bitmex2_bot }}</td>
          <td class="bg4">{{ item.positions.bitmex_bot }}</td>
          <td class="bg5">{{ item.positions.deribit_bot }}</td>
          <td class="blue">{{ item.summary }}</td>
          <td class="blue">{{ item.volume }}</td>
        </tr>
        </tbody>
      </table>

    </div>
  </div>
</template>
<script lang="ts" setup>
const props = defineProps({
  data: {},
  longVolume: {},
  shortVolume: {}
})
</script>
<style>
.admin__risk-table tr td.bg1 {
  background: #F5E5D5;
}
.admin__risk-table tr td.bg2 {
  background: #DFD6EE;
}
.admin__risk-table tr td.bg3 {
  background: #BDE1E8;
}
.admin__risk-table tr td.bg4 {
  background: #D3E9B4;
}
.admin__risk-table tr td.bg5 {
  background: #C4EBD6;
}
.admin__risk-table tr td.blue {
  background: #3758F9;
  color: #FFFFFF;
}
.admin__risk-table tr td {
  white-space: nowrap;
  width: 135px;
  padding: 7px 5px;
  box-sizing: border-box;
  text-align: center;
  border-right: 2px solid #F9FEFF;
}
.admin__risk-table tr:first-of-type td {
  font-weight: 700;
  text-transform: uppercase;
  font-size: 18px;
  line-height: 110%;
  text-align: center;
}
.admin__risk-table tr td:first-of-type {
  font-size: 16px;
  font-weight: 400;
  text-align: left;
}
.admin__risk-table tr:nth-of-type(2n) td {
  filter: opacity(0.8);
}
.admin__risk-table {
  overflow: auto;
}
.admin__risk-table table {
  width: auto;
}
.admin__risk-table {

}
.admin__risk-info-numb {
  font-size: 20px;
  font-weight: 700;
  margin-top: 15px;
}
.admin__risk-info-tt {
  font-size: 16px;
  line-height: 110%;
}
.admin__risk-info-item.bg {
  background: #3758F9;
  color: #FFFFFF;
}
.admin__risk-info-item {
  padding: 17px;
  margin-right: 25px;
  box-sizing: border-box;
  border-radius: 10px;
  background: #DDF0F3;
}
.admin__risk-info {
  display: flex;
  margin-bottom: 28px;
}
.admin__risk h2 {
  margin-bottom: 10px;

  font-size: 24px;
  line-height: 120%;
  text-transform: uppercase;
  font-weight: 400;
}
.admin__risk {
  margin-top: 30px;
}
</style>
<template>
  <div ref="chartContainer" class="chart-container"></div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { createChart, LineSeries } from 'lightweight-charts';

const chartContainer = ref<HTMLDivElement | null>(null);

// Пример данных из API
const apiData = ref([]);

onMounted(async () => {
  if (!chartContainer.value) return;

  const data = await useApiRequest(`/api/chart?exchange=bitfinex&account_id=279405`, {
    method: "GET",
  });

  apiData.value = data.data.value.data;

  const chart = createChart(chartContainer.value, {
    width: 1300,
    height: 450,
    layout: {
      backgroundColor: '#ffffff',
      textColor: '#000',
    },
    grid: {
      vertLines: { color: '#eee' },
      horzLines: { color: '#eee' },
    },
    timeScale: {
      tickMarkFormatter: (time: number) => {
        const date = new Date(time * 1000);
        const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
          "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

        const dateString = `${date.getDate()} ${monthNames[date.getMonth()]}`;
        const timeString = `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;

        // если время ближе к началу суток → показываем дату
        if (date.getHours() === 0 && date.getMinutes() === 0) {
          return dateString.toUpperCase();
        } else {
          return timeString;
        }
      },
    },
  });

  chart.timeScale().fitContent();

  const lineSeries = chart.addSeries(LineSeries);

  const chartData = apiData.value.map((item: any) => ({
    time: Math.floor(item.timestamp / 1000),
    value: item.value,
  }));

  lineSeries.setData(chartData);
});
</script>

<style>
#tv-attr-logo,
.tv-attr-logo {
  display: none !important;
  opacity: 0 !important;
  visibility: hidden !important;
}
.chart-container {
  width: 900px;
  height: 300px;
}
</style>
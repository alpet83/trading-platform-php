<template>
  <div style="padding: 0 40px; box-sizing: border-box;">
    <div class="inline-flex absolute top-[10px] z-[3]">
<!--      <a href="/" class="inline-flex items-center text-[#2F354E] text-[16px] no-underline">-->
<!--        <img src="assets/svg/arrow.svg" alt="Back" />-->
<!--        {{ $t('home') }}-->
<!--      </a>-->
    </div>
    <div class="flex mb-[30px] mt-[30px] flex-wrap justify-between items-center px-5 sm:px-0 w-full">
      <div class="z-20 flex w-full items-center justify-between">
        <CustomButton @click="toggleSignalModal">{{ $t('addSignal') }}</CustomButton>
        <AddSignalModal
            @confirm="confirmed"
            v-if="displaySignalModal"
            :setup="activeTab"
            @closeModal="toggleSignalModal"
        ></AddSignalModal>
      </div>
    </div>
    <div class="transition-all duration-300 w-full">
      <div
        v-if="apiError"
        class="w-full rounded border border-red-300 bg-red-50 p-4 text-red-800"
      >
        <div class="font-semibold mb-2">Signals API error</div>
        <pre class="whitespace-pre-wrap text-sm">{{ apiError }}</pre>
      </div>
      <div class="overflow-auto w-full">
        <table v-if="!apiError" class="w-full text-center text-base font-normal border-collapse">
          <thead>
          <tr>
            <th v-for="header in Object.keys(headers)" class="py-3 px-2 font-normal">
                <span class="inline-flex items-center">
                  <span>{{ headers[header] }}</span>
                  <img
                      v-if="haveSort(header)"
                      :class="{
                        'cursor-pointer': true,
                        'rotate-180': activeSort.field === header && activeSort.direction === 'asc'
                      }"
                      @click="toggleSort(header)"
                      src="assets/svg/sortArrow.svg"
                      alt="sort arrow"
                  />

                </span>
            </th>
          </tr>
          </thead>
          <tbody v-if="!inLoad" v-for="(dataArr, arrIndex) in data?.data?.pairs">
          <tr v-for="(datum, index) in dataArr.signals" class="border-b border-gray-300 last:border-none align-middle">
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background, true) }" class="text-center align-middle">
              {{ FormatTimestamp(datum.timestamp) }}
            </td>
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background) }" class="text-center align-middle">
              {{datum.signal_no}} / {{ datum.id }}
            </td>
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background, true) }" class="text-center align-middle">
              {{ datum.side }}
            </td>
            <td @click="fetchByPair(datum.pair)" v-if="index === 0 || data?.data?.signals" :style="{ color: datum.colors.font, background: getBg(datum.colors.background) }" :rowspan="data?.data?.signals ? 1 : dataArr.signals.length" class="text-center align-middle cursor-pointer hover:underline">
              {{ datum.pair }}
            </td>

            <!-- Multiplier -->
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background, true) }" class="text-center align-middle">
              <div v-if="editingField?.id === datum.id && editingField?.field === 'multiplier'">
                <input
                    v-model="editValue"
                    type="number"
                    class="border px-1 w-20"
                    @blur="saveEdit(datum)"
                    @keyup.enter="saveEdit(datum)"
                />
              </div>
              <span v-else @click="startEdit(datum, 'multiplier', datum.multiplier)" class="cursor-pointer hover:underline">
                {{ datum.multiplier }}
              </span>
            </td>

            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background) }" class="text-center align-middle cursor-pointer">
              {{ datum.accumulated_position }}
            </td>

            <!-- Limit Price -->
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background, true) }" class="p-5 text-start align-middle">
              <div class="inline-flex items-center justify-center cursor-pointer space-x-1">
                <label class="inline-flex items-center justify-center cursor-pointer">
                  <input
                      @input="toggleFlag(datum.id, 'toggle_lp')"
                      :checked="datum.flags.limit_price"
                      type="checkbox"
                      :id="arrIndex + '-' + index + '-limit'"
                      class="hidden peer"
                  />
                  <span
                      class="w-5 h-5 border border-gray-300 rounded flex items-center justify-center bg-white peer-checked:bg-blue-600 peer-checked:border-blue-600"
                  >
                    <img src="assets/svg/check.svg" alt="Check" />
                  </span>
                </label>

                <div v-if="editingField?.id === datum.id && editingField?.field === 'limit_price'">
                  <input
                      @click="datum.flags.limit_price = true"
                      v-model="editValue"
                      type="number"
                      class="border px-1 w-20"
                      @blur="saveEdit(datum)"
                      @keyup.enter="saveEdit(datum)"
                  />
                </div>
                <span
                    v-else
                    class="cursor-pointer hover:underline"
                    @click="startEdit(datum, 'limit_price', datum.limit_price)"
                >
                  ${{ datum.limit_price }}
                </span>
              </div>
            </td>

            <!-- Take Profit -->
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background) }" class="p-5 text-start align-middle">
              <div class="inline-flex items-center justify-center cursor-pointer space-x-1">
                <label class="inline-flex items-center justify-center cursor-pointer">
                  <input
                      @input="toggleFlag(datum.id, 'toggle_tp')"
                      :checked="datum.flags.take_profit"
                      type="checkbox"
                      :id="arrIndex + '-' + index + '-takeProfit'"
                      class="hidden peer"
                  />
                  <span class="w-5 h-5 border border-gray-300 rounded flex items-center justify-center bg-white peer-checked:bg-blue-600 peer-checked:border-blue-600">
                    <img src="assets/svg/check.svg" alt="Check" />
                  </span>
                </label>

                <div v-if="editingField?.id === datum.id && editingField?.field === 'take_profit'">
                  <input
                      @click="datum.flags.take_profit = true"
                      v-model="editValue"
                      type="number"
                      class="border px-1 w-20"
                      @blur="saveEdit(datum)"
                      @keyup.enter="saveEdit(datum)"
                  />
                </div>
                <span
                    v-else
                    class="cursor-pointer hover:underline"
                    @click="startEdit(datum, 'take_profit', datum.take_profit)"
                >
                  ${{ datum.take_profit }}
                </span>
              </div>
            </td>

            <!-- Stop Loss -->
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background, true) }" class="p-5 text-start align-middle">
              <div class="inline-flex items-center justify-center cursor-pointer space-x-1">
                <label class="inline-flex items-center justify-center cursor-pointer">
                  <input
                      @input="toggleFlag(datum.id, 'toggle_sl')"
                      :checked="datum.flags.stop_loss"
                      type="checkbox"
                      :id="arrIndex + '-' + index + '-stopLoss'"
                      class="hidden peer"
                  />
                  <span class="w-5 h-5 border border-gray-300 rounded flex items-center justify-center bg-white peer-checked:bg-blue-600 peer-checked:border-blue-600">
                    <img src="assets/svg/check.svg" alt="Check" />
                  </span>
                </label>

                <div v-if="editingField?.id === datum.id && editingField?.field === 'stop_loss'">
                  <input
                      @click="datum.flags.stop_loss = true"
                      v-model="editValue"
                      type="number"
                      class="border px-1 w-20"
                      @blur="saveEdit(datum)"
                      @keyup.enter="saveEdit(datum)"
                  />
                </div>
                <span
                    v-else
                    class="cursor-pointer hover:underline"
                    @click="startEdit(datum, 'stop_loss', datum.stop_loss)"
                >
                  ${{ datum.stop_loss }}
                </span>
                <span class="cursor-pointer">
                  {{ datum.stop_loss_diff_percent }}%
                </span>
              </div>
            </td>

            <td
                :style="{ color: datum.colors.font, background: getBg(datum.colors.background) }"
                class="text-center align-middle"
            >
              <label class="inline-flex items-center justify-center cursor-pointer space-x-1">
                <input
                    @input="toggleFlag(datum.id, 'toggle_se')"
                    :checked="datum.flags.stop_endless"
                    type="checkbox"
                    :id="arrIndex + '-' + index + '-check'"
                    class="hidden peer"
                />
                <span
                    class="w-5 h-5 border border-gray-300 rounded flex items-center justify-center bg-white peer-checked:bg-blue-600 peer-checked:border-blue-600"
                >
                    <img src="assets/svg/check.svg" alt="Check" />
                  </span>
              </label>
            </td>

            <td
                :style="{ color: datum.colors.font, background: getBg(datum.colors.background, true) }"
                class="text-center align-middle cursor-pointer"
            >
              ${{ datum.last_price }}
            </td>

            <!-- Comment -->
            <td :style="{ color: datum.colors.font, background: getBg(datum.colors.background) }" class="px-2 text-center align-middle">
              <div class="relative pt-[10px] pb-[10px] pl-[5px] pr-[5px]">
                <div class="absolute inset-y-0 left-0 flex items-center pl-2 pointer-events-none bottom-[5px] ml-[3px]">
                  <img src="assets/svg/edit.svg" alt="Comment" class="w-6 h-6"/>
                </div>
                <textarea
                    v-model="datum.comment"
                    @keyup.enter="saveEdit(datum, 'comment')"
                    placeholder="Comment"
                    @blur="saveEdit(datum, 'comment')"
                    style="padding: 5px 10px 5px 35px;"
                    class="w-full min-h-[32px] h-[10px] px-10 py-2 border border-gray-300 rounded-lg text-base text-black placeholder-gray-400 resize-vertical focus:outline-none focus:border-gray-700 overflow-hidden"
                ></textarea>
              </div>
            </td>

            <!-- Delete -->
            <td
                :style="{ color: datum.colors.font, background: getBg(datum.colors.background, true) }"
                class="h-full"
            >
              <div class="flex h-full items-center justify-center space-x-2">
                  <span class="flex items-center cursor-pointer text-red-600 hover:underline">
                    <div class="flex" @click="toggleDeleteSignalModal(datum.id)">
                      <img src="assets/svg/close.svg" class="mr-2" alt="Close" />
                      <span>{{ $t('headers.delete') }}</span>
                    </div>
                    <DeleteSignalModal
                        :id="datum.id"
                        @confirm="deleteSignal(datum.id)"
                        @close="toggleDeleteSignalModal('0')"
                        v-click-outside="handleClickOutside"
                        v-if="displayDeleteSignalModalId === datum.id"
                    ></DeleteSignalModal>
                  </span>
              </div>
            </td>
          </tr>
          </tbody>
        </table>
        <Loader class="mt-[50px]" v-if="inLoad"></Loader>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import CustomButton from "~/components/ui/buttons/CustomButton.vue";
import { type SignalData, type TableHeaders } from "~/components/table/table.types";
import { useI18n } from "vue-i18n";
import AddSignalModal from "~/components/table/modal/AddSignalModal.vue";
import DeleteSignalModal from "~/components/table/modal/DeleteSignalModal.vue";
import tinycolor from "tinycolor2";
import Loader from "~/components/ui/Loader.vue";
import { FormatTimestamp } from "~/helpers/date";
import { useSetupTabs } from "~/composables/setupTabs";

type ActiveSort = { field: string; direction: 'asc' | 'desc' | '' }
const { activeTab, refreshTick } = useSetupTabs();
const { t } = useI18n();
const data = ref<SignalData>();
const displaySignalModal = ref(false);
const inLoad = ref(false);
const displayDeleteSignalModalId = ref('0');
const activeSort = ref<ActiveSort>({ field: '', direction: '' });
let preventClose = false;
const editingField = ref<{ id: string, field: string } | null>(null);
const editValue = ref<string | number>("");
const activeFilter = ref<string | undefined>();
const apiError = ref<string>('');
// Nest API endpoint

watch(refreshTick, async () => {
  await fetchData(activeSort.value);
});

const headers: TableHeaders = {
  timestamp: t('headers.time'),
  id: t('headers.sigID'),
  side: t('headers.side'),
  pair: t('headers.pair'),
  multiplier: t('headers.mult'),
  accumulated_position: t('headers.accumPos'),
  limit_price: t('headers.limitPrice'),
  take_profit: t('headers.takeProfit'),
  stop_loss: t('headers.stopLoss'),
  slEndless: t('headers.slEndless'),
  last_price: t('headers.lastPrice'),
  comment: t('headers.comment'),
  delete: t('headers.delete'),
};

// =============================
// API wrappers (через Nest)
// =============================

async function fetchData(activeSort: ActiveSort = { field: '', direction: '' }, filter?: string) {
  const query: Record<string, string> = {
    setup: activeTab.value.toString(),
  };

  try {
    inLoad.value = true;
    apiError.value = '';

    if (activeSort.field) {
      query.sort = activeSort.field;
      if (activeSort.direction) query.order = activeSort.direction;
    }
    if (filter) query.filter = filter;

    const config = useRuntimeConfig();
    const responseData = await $fetch<SignalData>('/api/signals', {
      baseURL: config.public.baseURL as string,
      params: query,
      headers: {
        Authorization: prepareAuthHeader(),
      },
    });

    if (responseData?.data?.signals?.length) {
      responseData.data.pairs = [{ signals: responseData.data.signals }];
    }

    data.value = responseData;
  } catch (e) {
    const err = e as any;
    const status = err?.status || err?.response?.status;
    const statusText = err?.statusText || err?.response?.statusText;
    const body = err?.data || err?.response?._data || err?.response?.data;
    const message =
      typeof body === 'string'
        ? body
        : body?.message || err?.message || 'Unknown error';

    apiError.value = [
      status ? `HTTP ${status}${statusText ? ` ${statusText}` : ''}` : 'Request failed',
      `GET /api/signals?setup=${activeTab.value.toString()}`,
      `message: ${typeof message === 'string' ? message : JSON.stringify(message)}`,
    ].join('\n');

    console.error('[Table] failed to fetch signals', {
      query,
      status,
      statusText,
      body,
      error: err,
    });
  } finally {
    inLoad.value = false;
  }
}
async function saveEdit(datum: any, type?: string) {
  if (type === 'comment') {
    editingField.value = { id: datum.id, field: 'comment' };
  }
  if (!editingField.value) return;

  const { id, field } = editingField.value;
  const payload: any = { field, value: editValue.value, setup: activeTab.value.toString() };
  if (field === "comment") payload.text = datum.comment;

  editingField.value = null;
  datum[field] = field === 'comment' ? datum.comment : editValue.value;

  try {
    const config = useRuntimeConfig();
    const res = await $fetch<{ status: string }>(`/api/signals/${id}/edit`, {
      baseURL: config.public.baseURL as string,
      method: 'POST',
      body: payload,
      headers: { Authorization: prepareAuthHeader() },
    });
    if (res?.status !== 'success') throw new Error('Save failed');
  } catch (e) {
    console.error(e);
  }
}
async function toggleFlag(id: string, flag: string) {
  try {
    const config = useRuntimeConfig();
    const res = await $fetch<{ status: string }>(`/api/signals/${id}/toggle`, {
      baseURL: config.public.baseURL as string,
      method: 'POST',
      body: { flag, setup: activeTab.value.toString() },
      headers: { Authorization: prepareAuthHeader() },
    });
    if (res?.status !== 'success') throw new Error('Result is not success');
  } catch (e) {
    console.error(e);
  }
}
async function deleteSignal(id: string) {
  displayDeleteSignalModalId.value = '0';
  try {
    if (data.value?.data?.pairs) {
      data.value.data.pairs.forEach(pair => {
        pair.signals = pair.signals.filter(signal => signal.id !== id);
      });
    }
    const config = useRuntimeConfig();
    const res = await $fetch<{ status: string }>(`/api/signals/${id}/${activeTab.value.toString()}`, {
      baseURL: config.public.baseURL as string,
      method: 'DELETE',
      headers: { Authorization: prepareAuthHeader() },
    });
    if (res?.status !== 'success') throw new Error('Result is not success');
  } catch (e) {
    console.error(e);
  }
}
async function fetchByPair(pair: string) {
  if (activeFilter.value) {
    activeFilter.value = undefined;
  } else {
    activeFilter.value = pair;
  }
  await fetchData({ field: '', direction: '' }, activeFilter.value);
}
function startEdit(datum: any, field: string, value: string | number) {
  editingField.value = { id: datum.id, field };
  editValue.value = value;
}

function haveSort(header: string) {
  return !(header === 'delete' || header === 'comment' || header === 'slEndless');
}

async function toggleSort(header: string) {
  if (activeSort.value.field !== header) {
    activeSort.value = { field: header, direction: 'desc' };
  } else {
    if (activeSort.value.direction === 'desc') {
      activeSort.value.direction = 'asc';
    } else if (activeSort.value.direction === 'asc') {
      activeSort.value = { field: '', direction: '' };
    } else {
      activeSort.value.direction = 'desc';
    }
  }
  await fetchData(activeSort.value);
}

function getBg(color: string | undefined, lighten = false) {
  const base = color || "#FFFFFF";
  return lighten ? tinycolor(base).lighten(30).toHexString() : tinycolor(base).lighten(35).toHexString();
}

async function confirmed() {
  displaySignalModal.value = false;
  await fetchData(activeSort.value);
}

function toggleSignalModal() {
  displaySignalModal.value = !displaySignalModal.value;
}

function handleClickOutside() {
  if (preventClose) {
    preventClose = false;
    return;
  }
  displayDeleteSignalModalId.value = '0';
}

function toggleDeleteSignalModal(id: string) {
  displayDeleteSignalModalId.value = id;
  preventClose = true;
}
onBeforeMount(async () => {
  await fetchData();
});
</script>
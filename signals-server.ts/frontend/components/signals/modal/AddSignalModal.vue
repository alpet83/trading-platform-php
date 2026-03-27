<template>
  <div class="fixed inset-0 z-20 flex items-start bg-white/50">
    <div class="relative left-[80px] top-[125px] w-[340px] mt-10 rounded-2xl bg-white p-4 sm:w-[280px] sm:p-3">
      <!-- Close button -->
      <button class="absolute right-3 w-4 h-4 flex items-center justify-center" @click="closeModal">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M12.6671 3.33325L3.33374 12.6666M3.33374 3.33325L12.6671 12.6666" stroke="#D40D0D" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>

      <h2 class="mb-2 text-center text-xl font-semibold uppercase text-gray-900">
        {{ $t('modal.title') }}
      </h2>

      <div>
        <div class="space-y-5">
          <div>
            <label class="block mb-1 text-lg text-gray-900">{{ $t('modal.side') }}</label>
            <select v-model="form.side" class="w-full h-11 px-4 border border-gray-300 rounded-md bg-white text-gray-900 focus:border-gray-900">
              <option value="BUY">BUY</option>
              <option value="SELL">SELL</option>
            </select>
          </div>

          <div>
            <label class="block mb-1 text-lg text-gray-900">{{ $t('modal.pair') }}</label>
            <input :style="{borderColor: displayErrors && !form.pair ? 'red' : ''}" v-model="form.pair" type="text" :placeholder="$t('modal.placeholderPair')" class="w-full h-11 px-4 border rounded-md bg-white text-gray-900 placeholder-gray-400 focus:border-gray-900">
          </div>

          <div>
            <label class="block mb-1 text-lg text-gray-900">{{ $t('modal.mult') }}</label>
            <input v-model="form.multiplier" type="text" placeholder="000000" class="w-full h-11 px-4 border border-gray-300 rounded-md bg-white text-gray-900 focus:border-gray-900">
          </div>

          <div>
            <label class="block mb-1 text-lg text-gray-900">{{ $t('modal.signalNo') }}</label>
            <input :style="{borderColor: displayErrors && !form.signal_no ? 'red' : ''}" v-model="form.signal_no" type="text" placeholder="00" class="w-full h-11 px-4 border border-gray-300 rounded-md bg-white text-gray-900 focus:border-gray-900">
          </div>
        </div>

        <div class="mt-4 flex justify-between">
          <button @click="confirm" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            <Loader color="white" v-if="inLoad"></Loader>
            <div v-else>{{ $t('modal.ok') }}</div>
          </button>
          <button @click="emit('closeModal')" type="button" class="px-4 py-2 bg-gray-300 text-gray-900 rounded-md hover:bg-gray-400">{{ $t('modal.cancel') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">

import type {AddSignalForm} from "~/components/signals/signal.types";
import Loader from "~/components/ui/Loader.vue";

const props = defineProps<{ setup: number | string }>();

const form = ref<AddSignalForm>({
  side: 'BUY',
  pair: '',
  multiplier: '',
  signal_no : '',
  setup: String(props.setup ?? 0),
})
const displayErrors = ref(false)
const inLoad = ref(false)
const emit = defineEmits<{
(e: "closeModal"): void;
  (e: "confirm", form: AddSignalForm): void;
}>();
const closeModal = () => {
  emit('closeModal')
}
async function confirm() {
  if (!form.value.pair || !form.value.signal_no || !form.value.pair) {
    displayErrors.value = true
    return
  }
  inLoad.value = true
  try {
    form.value.pair = form.value.pair.toUpperCase()
    const params = new URLSearchParams();
    for (const key in form.value) {
      params.append(key, form.value[key]);
    }
    const res = await useApiRequest(`/api/signals/add`, {
      method: "POST",
      body: JSON.stringify(form.value),
    });
    const data = await res;
    if (data?.status !== 'success') {
      throw new Error('Result is not success')
    }
  } catch (e) {
    console.log(e);
  } finally {
    inLoad.value = false
    emit('confirm', form.value)
  }
}
</script>

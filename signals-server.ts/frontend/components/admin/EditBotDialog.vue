<template>
  <transition name="fade">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
      <div class="bg-white rounded-lg shadow-lg w-[900px] max-w-full p-6">
        <h3 class="text-lg font-semibold mb-4">Редактировать бота</h3>

        <div class="grid grid-cols-2 gap-6">
          <!-- Колонка 1: Основное -->
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Bot Name</label>
              <input
                  v-model="form.applicant"
                  :disabled="!form.toCreate"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Account ID</label>
              <input
                  v-model.number="form.account_id"
                  :disabled="!form.toCreate"
                  type="number"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  @input="form.account_id = form.account_id ? Number(form.account_id) : ''"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Exchange</label>
              <input
                  v-model="form.config.exchange"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Debug Pair</label>
              <input
                  v-model="form.config.debug_pair"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Report Color</label>
              <input
                  v-model="form.config.report_color"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Position Coef</label>
              <input
                  v-model="form.config.position_coef"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <!-- Колонка 2: Торговля -->
          <div class="space-y-4">
            <div class="flex items-center gap-2">
              <input
                  type="checkbox"
                  v-model="form.config.trade_enabled"
                  class="w-4 h-4 text-blue-600"
              />
              <span class="text-gray-700">Trade Enabled</span>
            </div>
            <div class="flex items-center gap-2">
              <input
                  type="checkbox"
                  v-model="form.config.monitor_enabled"
                  class="w-4 h-4 text-blue-600"
              />
              <span class="text-gray-700">Monitor Enabled</span>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Min Order Cost</label>
              <input
                  v-model="form.config.min_order_cost"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Max Order Cost</label>
              <input
                  v-model="form.config.max_order_cost"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Max Limit Distance</label>
              <input
                  v-model="form.config.max_limit_distance"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Signals Setup</label>
              <input
                  v-model="form.config.signals_setup"
                  type="text"
                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <!-- Колонка 3: Мониторинг и API (только мониторинг редактируем) -->
<!--          <div class="space-y-4">-->
            <!-- Остальные поля disabled -->
<!--            <div v-for="key in disabledFields" :key="key">-->
<!--              <label class="block text-sm font-medium text-gray-400">{{ formatLabel(key) }}</label>-->
<!--              <input-->
<!--                  v-model="form.config[key]"-->
<!--                  type="text"-->
<!--                  disabled-->
<!--                  class="mt-1 block w-full px-3 py-2 border rounded-lg text-sm bg-gray-100 cursor-not-allowed"-->
<!--              />-->
<!--            </div>-->
<!--          </div>-->
        </div>

        <div class="flex justify-end gap-3 mt-6">
          <button @click="cancel" class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300 dark:text-black">Отмена</button>
          <button @click="save" class="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">Сохранить</button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { reactive, watch } from 'vue'

const props = defineProps({
  bot: { type: Object, default: () => ({}) }
})

const emit = defineEmits(['save', 'cancel'])

const editableFields = [
  'exchange', 'trade_enabled', 'position_coef', 'monitor_enabled',
  'min_order_cost', 'max_order_cost', 'max_limit_distance', 'signals_setup',
  'report_color', 'debug_pair'
]

const disabledFields = [
  'api_key_name', 'api_secret_name', 'api_secret_sep', 'api_secret_sep_',
  'max_pos_cost', 'max_pos_amount', 'shorts_mult', 'last_nonce',
  'limit_base_ttl', 'order_timeout'
]

const form = reactive({
  bot_name: '',
  account_id: '',
  toCreate: false,
  config: {
    exchange: '', trade_enabled: '0', position_coef: '', monitor_enabled: '0',
    min_order_cost: '', max_order_cost: '', max_limit_distance: '', signals_setup: '',
    report_color: '', debug_pair: '', api_key_name: '', api_secret_name: '',
    api_secret_sep: '', api_secret_sep_: '', max_pos_cost: '', max_pos_amount: '',
    shorts_mult: '', last_nonce: '', limit_base_ttl: '', order_timeout: ''
  }
})

function formatLabel(key) {
  return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

watch(
    () => props.bot,
    (newBot) => {
      if (!newBot) return
      form.applicant = newBot.applicant || ''
      form.toCreate = newBot.toCreate
      form.account_id = newBot.account_id || ''
      form.config = { ...form.config, ...newBot.config }
      form.config.monitor_enabled = newBot.config.monitor_enabled == 1 || newBot.config.monitor_enabled == true
      form.config.trade_enabled = newBot.config.trade_enabled == 1 || newBot.config.trade_enabled == true
    },
    { immediate: true }
)

function save() {
  ['trade_enabled', 'monitor_enabled'].forEach(
      key => form.config[key] = form.config[key] ? '1' : '0'
  )
  form.bot_name = form.applicant
  emit('save', { ...form })
}

function cancel() {
  emit('cancel')
}
</script>

<style scoped>
.dark input {
  color: black
}
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
<template>
  <transition name="fade">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
      <div class="bg-white rounded-lg shadow-lg w-96 p-6">
        <h3 class="text-lg font-semibold mb-4 dark:text-black">Редактировать пользователя</h3>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">ID</label>
            <input
                v-model.number="form.id"
                type="number"
                :disabled="!form.toCreate"
                class="mt-1 dark:text-black block w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                @input="form.id = form.id ? Number(form.id) : ''"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Username</label>
            <input
                v-model="form.user_name"
                :disabled="!form.toCreate"
                type="text"
                class="mt-1 block dark:text-black w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div class="flex items-center gap-2 mt-2">
            <input
                type="checkbox"
                v-model="form.enabled"
                class="w-4 h-4 text-blue-600"
            />
            <span class="text-gray-700">Активен</span>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Права</label>
            <div class="flex flex-col">
              <label
                  v-for="role in roleOptions"
                  :key="role.value"
                  class="flex mt-[15px] items-center gap-2 cursor-pointer p-2 border rounded-lg hover:bg-gray-50"
              >
                <input
                    type="checkbox"
                    :value="role.value"
                    v-model="form.rights"
                    class="w-4 h-4 text-blue-600"
                />
                <span class="text-gray-700">{{ role.label }}</span>
              </label>
            </div>
          </div>
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
import { defineProps, defineEmits } from 'vue'

const props = defineProps({
  user: { type: Object, default: () => ({}) }
})

const emit = defineEmits(['save', 'cancel'])

const form = reactive({
  id: '',
  toCreate: false,
  enabled: false,
  user_name: '',
  rights: []
})

// Роли с русскими названиями
const roleOptions = [
  { label: 'Администратор', value: 'admin' },
  { label: 'Просмотр', value: 'view' },
  { label: 'Торговля', value: 'trade' }
]

// Обновляем форму при открытии
watch(
    () => props.user,
    (newUser) => {
      form.id = newUser.id || ''
      form.toCreate = newUser.toCreate
      form.enabled = newUser.enabled == 1 || newUser.enabled === true
      form.user_name = newUser.user_name || ''
      form.rights = [...(newUser.rights || [])]
    },
    { immediate: true }
)

function save() {
  emit('save', { ...form, enabled: form.enabled ? 1 : 0 })
}

function cancel() {
  emit('cancel')
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
<template>
  <div class="space-y-4">
    <!-- Search -->
    <div v-if="searchable" class="flex justify-between">
      <input
          v-model="searchQuery"
          type="text"
          placeholder="Поиск..."
          class="placeholder-black dark:text-black w-64 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
      <button
          @click="$emit('clickBtn')"
          class="px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700"
      >
        {{btn_title}}
      </button>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="min-w-full border border-gray-200 rounded-lg overflow-hidden">
        <thead>
        <tr>
          <slot name="header" />
        </tr>
        </thead>

        <tbody class="divide-y divide-gray-100">
        <tr
            v-for="(row, index) in paginatedData"
            :key="rowKey ? row[rowKey] : index"
            class="hover:bg-gray-50 dark:hover:bg-gray-600"
        >
          <slot name="row" :row="row" :index="index" />
        </tr>

        <tr v-if="!paginatedData?.length">
          <td
              :colspan="columnsCount"
              class="px-4 py-6 text-center text-gray-400 text-sm"
          >
            Нет данных
          </td>
        </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div
        v-if="paginated"
        class="flex items-center justify-between text-sm text-gray-600"
    >
      <span>
        Показано
        {{ startItem }}–{{ endItem }}
        из {{ filteredData?.length }}
      </span>

      <div class="flex gap-1">
        <button
            @click="prevPage"
            :disabled="currentPage === 1"
            class="px-3 py-1 border rounded disabled:opacity-50"
        >
          ←
        </button>

        <button
            v-for="page in totalPages"
            :key="page"
            @click="currentPage = page"
            class="px-3 py-1 border rounded"
            :class="page === currentPage
            ? 'bg-blue-500 text-white border-blue-500'
            : 'hover:bg-gray-100'"
        >
          {{ page }}
        </button>

        <button
            @click="nextPage"
            :disabled="currentPage === totalPages"
            class="px-3 py-1 border rounded disabled:opacity-50"
        >
          →
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  data: {
    type: Array,
    required: true,
  },
  btn_title: {
    type: String
  },
  rowKey: {
    type: String,
    default: null,
  },
  columnsCount: {
    type: Number,
    required: true,
  },

  /* Search */
  searchable: {
    type: Boolean,
    default: false,
  },
  searchKeys: {
    type: Array,
    default: () => [],
  },

  /* Pagination */
  paginated: {
    type: Boolean,
    default: false,
  },
  perPage: {
    type: Number,
    default: 10,
  },
})

const searchQuery = ref('')
const currentPage = ref(1)

/* Reset page on search */
watch(searchQuery, () => {
  currentPage.value = 1
})

const filteredData = computed(() => {
  if (!props.searchable || !searchQuery.value) {
    return props.data
  }

  const query = searchQuery.value.toLowerCase()

  return props.data.filter(row =>
      props.searchKeys.some(key =>
          String(row[key] ?? '').toLowerCase().includes(query)
      )
  )
})

const totalPages = computed(() =>
    Math.ceil(filteredData.value?.length / props.perPage)
)

const paginatedData = computed(() => {
  if (!props.paginated) {
    return filteredData.value
  }

  const start = (currentPage.value - 1) * props.perPage
  return filteredData.value?.slice(start, start + props.perPage)
})

const startItem = computed(() =>
    filteredData.value?.length
        ? (currentPage.value - 1) * props.perPage + 1
        : 0
)

const endItem = computed(() =>
    Math.min(currentPage.value * props.perPage, filteredData.value?.length)
)

const prevPage = () => {
  if (currentPage.value > 1) currentPage.value--
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) currentPage.value++
}
</script>
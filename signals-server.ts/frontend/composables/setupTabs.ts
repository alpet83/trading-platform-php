import { ref, watch, onMounted } from 'vue'

// ключ для localStorage
const STORAGE_KEY = 'activeSetupTab'

// реактивные переменные
const activeTab = ref<number>(9)
const refreshTick = ref<number>(0)

export function useSetupTabs() {
  // при загрузке берем из localStorage
  onBeforeMount(() => {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved) {
      const parsed = parseInt(saved, 10)
      if (!isNaN(parsed)) {
        activeTab.value = parsed
      }
    }
  })

  // следим за изменением activeTab и сохраняем
  watch(activeTab, (val) => {
    localStorage.setItem(STORAGE_KEY, String(val))
  })

  function setActiveTab(tab: number) {
    if (activeTab.value !== tab) {
      activeTab.value = tab
    }
    refreshTick.value++
  }

  return {
    activeTab,
    refreshTick,
    setActiveTab,
  }
}
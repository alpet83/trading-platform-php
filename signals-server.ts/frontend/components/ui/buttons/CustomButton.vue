<template>
  <button
      :style="{
        '--btn-color': color,
        '--btn-color-dark': darken(color, 0.15),
        '--btn-color-shadow': transparentize(color, 0.6),
        '--btn-color-active-shadow': transparentize(darken(color, 0.3), 0.5),
      }"
      class="inline-flex items-center justify-center px-4 py-2
           text-white text-[18px] leading-[20px] uppercase
           rounded-[10px] relative z-20
           h-[45px]
           cursor-pointer
           bg-[var(--btn-color)]
           hover:shadow-[0_10px_10px_var(--btn-color-shadow)]
           active:shadow-[inset_0_4px_10px_var(--btn-color-active-shadow)]
           dark:hover:shadow-[0_10px_10px_var(--btn-color-dark)]"
  >
    <div class="flex items-center p-[10px]">
      <img src="@/assets/svg/plus.svg" alt="Plus" class="mr-[5px]" />
      <slot />
    </div>
  </button>
</template>

<script setup lang="ts">
import { Palette } from "@/assets/palette"
import tinycolor from "tinycolor2"

const props = defineProps({
  color: { type: String, default: Palette.BLUE }
})

// Функции для изменения цвета и прозрачности теней
const darken = (col: string, amount = 0.2) => tinycolor(col).darken(amount * 100).toHexString()
const transparentize = (col: string, amount = 0.6) => tinycolor(col).setAlpha(1 - amount).toRgbString()
</script>

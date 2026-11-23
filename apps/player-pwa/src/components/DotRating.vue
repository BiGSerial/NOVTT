<script setup lang="ts">
import { computed, ref } from 'vue'

// Accessible dot rating: a radio group (WAI-ARIA APG) with roving focus. Arrow keys move,
// Home/End jump, Space/Enter select. "Nenhum" is offered when the minimum is 0.
const props = defineProps<{
  id: string
  label: string
  modelValue: number | null
  min: number
  max: number
  disabled?: boolean
  describedBy?: string
  invalid?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: number] }>()

const values = computed(() => Array.from({ length: props.max - props.min + 1 }, (_, i) => props.min + i))
const current = computed(() => props.modelValue ?? props.min)
const buttons = ref<HTMLButtonElement[]>([])

function choose(value: number): void {
  if (!props.disabled) emit('update:modelValue', value)
}

function onKey(event: KeyboardEvent, index: number): void {
  const last = values.value.length - 1
  const next = { ArrowRight: index + 1, ArrowUp: index + 1, ArrowLeft: index - 1, ArrowDown: index - 1, Home: 0, End: last }[event.key]
  if (next === undefined) return
  event.preventDefault()
  const target = Math.max(0, Math.min(last, next))
  choose(values.value[target])
  buttons.value[target]?.focus()
}

function optionLabel(value: number): string {
  return value === 0 ? 'Nenhum' : `${value} de ${props.max}`
}
</script>

<template>
  <div class="dot-rating">
    <span :id="`${id}-label`" class="field-label">{{ label }}</span>
    <div
      :id="id"
      role="radiogroup"
      :aria-labelledby="`${id}-label`"
      :aria-describedby="describedBy"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-disabled="disabled ? 'true' : undefined"
    >
      <button
        v-for="(value, index) in values"
        :key="value"
        ref="buttons"
        type="button"
        role="radio"
        class="dot"
        :class="{ filled: value !== 0 && value <= current, zero: value === 0 }"
        :aria-checked="value === current ? 'true' : 'false'"
        :aria-label="optionLabel(value)"
        :tabindex="value === current ? 0 : -1"
        :disabled="disabled"
        @click="choose(value)"
        @keydown="onKey($event, index)"
      />
      <span class="dot-value" aria-hidden="true">{{ current }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * One rated field in the guided builder: name, the value large, − and + buttons, the values it
 * drives (e.g. its modifier) next to it, and any problem right below. Buttons respect the
 * schema bounds; the server still validates every change.
 */
const props = defineProps<{
  id: string
  label: string
  value: number
  min: number
  max: number
  dots?: boolean
  linked?: { label: string; value: unknown }[]
  /** False when no creation budget for this field has points left. */
  canRaise: boolean
  errors?: string[]
  /** Held by a choice (e.g. "Linhagem: Brasa"): both buttons off and the reason shown. */
  locked?: string | null
}>()
const emit = defineEmits<{ set: [value: number] }>()
const signed = (v: unknown) => String(v)
</script>

<template>
  <div class="stat-row" :class="{ invalid: errors?.length, locked: !!locked }">
    <div class="stat-name">
      <span :id="`${id}-label`">{{ label }}</span>
      <span v-for="l in linked" :key="l.label" class="stat-linked" :title="l.label">{{ l.label }} <b>{{ signed(l.value) }}</b></span>
    </div>
    <div class="stat-control" role="group" :aria-labelledby="`${id}-label`">
      <button type="button" class="stat-btn" :disabled="!!locked || value <= min" :aria-label="`Diminuir ${label}`" @click="emit('set', value - 1)">−</button>
      <output class="stat-value" :aria-live="'polite'">
        {{ value }}
        <span v-if="dots" class="stat-dots" aria-hidden="true"><i v-for="n in max" :key="n" :class="{ on: n <= value }" /></span>
      </output>
      <button type="button" class="stat-btn" :disabled="!!locked || value >= max || !canRaise" :aria-label="`Aumentar ${label}`" :title="!canRaise && value < max ? 'Sem pontos para distribuir' : undefined" @click="emit('set', value + 1)">+</button>
    </div>
    <p v-if="locked" class="stat-locked">🔒 Fixado por {{ locked }}</p>
    <p v-for="(e, i) in errors" :key="i" class="stat-error" role="alert">{{ e }}</p>
  </div>
</template>

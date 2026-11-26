<script setup lang="ts">
import { computed } from 'vue'

/** A threat clock as a dial of segments (click a segment to fill up to it; click the last to empty one). */
const props = defineProps<{ label: string; segments: number; filled: number; size?: number; editable?: boolean; tone?: string }>()
const emit = defineEmits<{ set: [filled: number] }>()
const R = 44
const arcs = computed(() => Array.from({ length: props.segments }, (_, i) => {
  const a0 = (i / props.segments) * Math.PI * 2 - Math.PI / 2
  const a1 = ((i + 1) / props.segments) * Math.PI * 2 - Math.PI / 2
  const p = (a: number, r: number) => `${50 + Math.cos(a) * r} ${50 + Math.sin(a) * r}`
  return `M50 50 L${p(a0, R)} A${R} ${R} 0 0 1 ${p(a1, R)} Z`
}))
function click(i: number): void {
  if (!props.editable) return
  emit('set', props.filled === i + 1 ? i : i + 1)
}
</script>

<template>
  <figure class="clock-dial" :class="[`tone-${tone ?? 'threat'}`, { full: filled >= segments }]" :style="{ '--size': `${size ?? 54}px` }">
    <svg viewBox="0 0 100 100" role="group" :aria-label="`${label}: ${filled} de ${segments}`">
      <circle cx="50" cy="50" r="47" class="dial-rim" />
      <path
        v-for="(d, i) in arcs" :key="i" :d="d" class="dial-seg" :class="{ on: i < filled }"
        :tabindex="editable ? 0 : -1" :role="editable ? 'button' : undefined" :aria-label="editable ? `${label}: marcar ${i + 1}` : undefined"
        @click="click(i)" @keydown.enter.prevent="click(i)" @keydown.space.prevent="click(i)"
      />
      <circle cx="50" cy="50" r="7" class="dial-hub" />
    </svg>
    <figcaption>{{ label }}<small>{{ filled }}/{{ segments }}</small></figcaption>
  </figure>
</template>

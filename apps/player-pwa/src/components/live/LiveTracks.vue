<script setup lang="ts">
import { ref, watch } from 'vue'
import type { LiveState, ResourceTrack } from '../../live/types'

/**
 * Resources and conditions. Editable for the master: big ± steppers and condition chips, saved
 * as one change with the known revision (conflicts surface instead of overwriting).
 */
const props = defineProps<{ live: LiveState | null; editable?: boolean; busy?: boolean }>()
const emit = defineEmits<{ save: [resources: ResourceTrack[], conditions: string[]] }>()
const COMMON = ['Ferido', 'Caído', 'Assustado', 'Cego', 'Envenenado', 'Exausto', 'Escondido', 'Inspirado']
const custom = ref('')
const conditions = ref<string[]>([])
watch(() => props.live, l => { conditions.value = [...(l?.conditions ?? [])] }, { immediate: true })

function step(t: ResourceTrack, delta: number): void {
  const resources = (props.live?.resources ?? []).map(r => (r.id === t.id ? { ...r, current: Math.max(-99, Math.min(r.max, r.current + delta)) } : r))
  emit('save', resources, props.live?.conditions ?? [])
}
function toggle(c: string): void {
  const has = conditions.value.includes(c)
  const next = has ? conditions.value.filter(x => x !== c) : [...conditions.value, c]
  emit('save', props.live?.resources ?? [], next)
}
function add(): void {
  const c = custom.value.trim().slice(0, 40)
  if (c && !conditions.value.includes(c)) emit('save', props.live?.resources ?? [], [...conditions.value, c])
  custom.value = ''
}
const pct = (t: ResourceTrack) => `${Math.max(0, Math.min(100, (t.current / t.max) * 100))}%`
</script>

<template>
  <div class="tracks">
    <div v-for="t in live?.resources ?? []" :key="t.id" class="track">
      <span class="track-label">{{ t.label }}</span>
      <button v-if="editable" type="button" class="step" :disabled="busy" :aria-label="`${t.label}: menos 1`" @click="step(t, -1)">−</button>
      <span class="track-bar" role="meter" :aria-valuenow="t.current" :aria-valuemin="0" :aria-valuemax="t.max" :aria-label="t.label"><span :style="{ width: pct(t) }" :class="{ low: t.current <= t.max / 3 }" /></span>
      <span class="track-value">{{ t.current }}/{{ t.max }}</span>
      <button v-if="editable" type="button" class="step" :disabled="busy" :aria-label="`${t.label}: mais 1`" @click="step(t, 1)">+</button>
    </div>
    <p v-if="!(live?.resources?.length)" class="muted small">Sem recursos registrados.</p>
    <div class="conditions" :aria-label="editable ? 'Condições (toque para ligar ou desligar)' : 'Condições'">
      <template v-if="editable">
        <button v-for="c in [...new Set([...COMMON, ...conditions])]" :key="c" type="button" class="chip" :class="{ on: conditions.includes(c) }" :aria-pressed="conditions.includes(c)" :disabled="busy" @click="toggle(c)">{{ c }}</button>
        <form class="chip-add" @submit.prevent="add"><input v-model="custom" maxlength="40" placeholder="Outra condição" aria-label="Nova condição" /></form>
      </template>
      <template v-else>
        <span v-for="c in conditions" :key="c" class="chip on">{{ c }}</span>
      </template>
    </div>
  </div>
</template>

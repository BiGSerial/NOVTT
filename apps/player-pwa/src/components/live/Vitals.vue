<script setup lang="ts">
import { ref } from 'vue'
import type { LiveState, ResourceTrack } from '../../live/types'

/**
 * Resource tracks as bars with quick steps, plus conditions. Editable for the master: −3 / −1 /
 * +1 / +3 and condition chips, each saved with the known revision (conflicts surface).
 */
const props = defineProps<{ live: LiveState | null; editable?: boolean; busy?: boolean; big?: boolean }>()
const emit = defineEmits<{ resources: [r: ResourceTrack[]]; conditions: [c: string[]] }>()
const COMMON = ['Ferido', 'Caído', 'Assustado', 'Cego', 'Envenenado', 'Exausto', 'Escondido', 'Inspirado', 'Preso', 'Em chamas']
const custom = ref('')
const showAll = ref(false)
function step(t: ResourceTrack, d: number): void {
  emit('resources', (props.live?.resources ?? []).map(r => (r.id === t.id ? { ...r, current: Math.max(-99, Math.min(r.max, r.current + d)) } : r)))
}
function toggle(c: string): void {
  const list = props.live?.conditions ?? []
  emit('conditions', list.includes(c) ? list.filter(x => x !== c) : [...list, c])
}
function add(): void {
  const c = custom.value.trim().slice(0, 40)
  if (c && !(props.live?.conditions ?? []).includes(c)) emit('conditions', [...(props.live?.conditions ?? []), c])
  custom.value = ''
}
const pct = (t: ResourceTrack) => `${Math.max(0, Math.min(100, t.max > 0 ? (t.current / t.max) * 100 : 0))}%`
const tone = (t: ResourceTrack) => (t.current <= 0 ? 'down' : t.current <= t.max / 3 ? 'low' : t.current <= (2 * t.max) / 3 ? 'mid' : 'ok')
</script>

<template>
  <div class="vitals" :class="{ big }">
    <div v-for="t in live?.resources ?? []" :key="t.id" class="vital" :class="tone(t)">
      <div class="vital-top"><span class="vital-label">{{ t.label }}</span><span class="vital-value"><b>{{ t.current }}</b>/{{ t.max }}</span></div>
      <span class="vital-bar" role="meter" :aria-valuenow="t.current" :aria-valuemin="0" :aria-valuemax="t.max" :aria-label="t.label"><span :style="{ width: pct(t) }" /></span>
      <div v-if="editable" class="vital-steps">
        <button type="button" :disabled="busy" :aria-label="`${t.label} menos 3`" @click="step(t, -3)">−3</button>
        <button type="button" :disabled="busy" :aria-label="`${t.label} menos 1`" @click="step(t, -1)">−1</button>
        <button type="button" :disabled="busy" :aria-label="`${t.label} mais 1`" @click="step(t, 1)">+1</button>
        <button type="button" :disabled="busy" :aria-label="`${t.label} mais 3`" @click="step(t, 3)">+3</button>
      </div>
    </div>
    <div class="conditions" :aria-label="editable ? 'Condições (toque para ligar ou desligar)' : 'Condições'">
      <template v-if="editable">
        <button v-for="c in [...new Set([...(live?.conditions ?? []), ...(showAll ? COMMON : [])])]" :key="c" type="button" class="chip cond" :class="{ on: live?.conditions?.includes(c) }" :aria-pressed="live?.conditions?.includes(c) ?? false" :disabled="busy" @click="toggle(c)">{{ c }}</button>
        <button type="button" class="chip ghosty" :aria-expanded="showAll" @click="showAll = !showAll">{{ showAll ? 'fechar' : '+ condição' }}</button>
        <form v-if="showAll" class="chip-add" @submit.prevent="add"><input v-model="custom" maxlength="40" placeholder="Outra…" aria-label="Nova condição" /></form>
      </template>
      <template v-else>
        <span v-for="c in live?.conditions ?? []" :key="c" class="chip cond on">{{ c }}</span>
      </template>
    </div>
  </div>
</template>

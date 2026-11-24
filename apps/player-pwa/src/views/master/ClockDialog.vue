<script setup lang="ts">
import { computed, reactive } from 'vue'
import ActionDialog from '../../components/ui/ActionDialog.vue'
import ClockDial from '../../components/live/ClockDial.vue'
import type { Clock, ClockTone, ClockVisibility } from '../../live/types'
import { useMaster } from './context'

/** Create or edit a clock: name, size, tone and who sees it (NT-11). */
const props = defineProps<{ clock?: Clock | null }>()
const emit = defineEmits<{ close: [] }>()
const m = useMaster()
const TONES: { id: ClockTone; name: string; hint: string }[] = [
  { id: 'threat', name: 'Ameaça', hint: 'algo ruim se aproxima' },
  { id: 'progress', name: 'Progresso', hint: 'um objetivo avançando' },
  { id: 'mystery', name: 'Mistério', hint: 'algo sendo descoberto' },
  { id: 'neutral', name: 'Neutro', hint: 'tempo, recursos, outros' },
]
const SEES: { id: ClockVisibility; name: string; hint: string }[] = [
  { id: 'master', name: '🔒 Só eu', hint: 'fica no seu painel' },
  { id: 'table', name: '👁 Toda a mesa', hint: 'todos os jogadores veem' },
  { id: 'players', name: '👤 Jogadores escolhidos', hint: 'só quem você marcar' },
]
const players = computed(() => m.s.value.members.filter(x => x.role !== 'master'))
const f = reactive({
  label: props.clock?.label ?? '',
  segments: props.clock?.segments ?? 4,
  filled: props.clock?.filled ?? 0,
  tone: props.clock?.tone ?? 'threat' as ClockTone,
  visibility: props.clock?.visibility ?? 'master' as ClockVisibility,
  player_ids: [...(props.clock?.player_ids ?? [])],
})
const valid = computed(() => f.label.trim().length > 0 && (f.visibility !== 'players' || f.player_ids.length > 0))
const size = (d: number) => { f.segments = Math.max(2, Math.min(12, f.segments + d)); f.filled = Math.min(f.filled, f.segments) }
function togglePlayer(id: string): void {
  f.player_ids = f.player_ids.includes(id) ? f.player_ids.filter(x => x !== id) : [...f.player_ids, id]
}
function save(): void {
  if (!valid.value) return
  const c = { label: f.label.trim(), segments: f.segments, filled: f.filled, tone: f.tone, visibility: f.visibility, player_ids: f.visibility === 'players' ? f.player_ids : [] }
  if (props.clock) m.updateClock({ ...props.clock, ...c })
  else m.addClock(c)
  emit('close')
}
function remove(): void {
  if (props.clock) m.removeClock(props.clock)
  emit('close')
}
</script>

<template>
  <ActionDialog :title="clock ? 'Editar relógio' : 'Novo relógio'" @close="emit('close')" @submit="save">
    <div class="clock-form">
      <div class="clock-preview" :class="`tone-${f.tone}`">
        <ClockDial :label="f.label || 'Relógio'" :segments="f.segments" :filled="f.filled" :tone="f.tone" :size="110" editable @set="n => f.filled = n" />
      </div>
      <div class="clock-fields">
        <label>Nome <input v-model="f.label" maxlength="80" required placeholder="Ex.: A matilha se aproxima" autofocus /></label>
        <div class="field-row">
          <span class="field-label">Partes</span>
          <span class="stepper-inline">
            <button type="button" class="step" aria-label="Menos partes" :disabled="f.segments <= 2" @click="size(-1)">−</button>
            <output aria-live="polite">{{ f.segments }}</output>
            <button type="button" class="step" aria-label="Mais partes" :disabled="f.segments >= 12" @click="size(1)">+</button>
          </span>
          <span class="muted small">Preenchido: {{ f.filled }} (toque no relógio)</span>
        </div>
      </div>
    </div>

    <fieldset class="choice-set">
      <legend>Tipo</legend>
      <div class="choice-tiles" role="radiogroup" aria-label="Tipo do relógio">
        <button v-for="t in TONES" :key="t.id" type="button" role="radio" class="choice-tile" :class="[`tone-${t.id}`, { on: f.tone === t.id }]" :aria-checked="f.tone === t.id" @click="f.tone = t.id">
          <i class="tone-dot" aria-hidden="true" /><b>{{ t.name }}</b><small>{{ t.hint }}</small>
        </button>
      </div>
    </fieldset>

    <fieldset class="choice-set">
      <legend>Quem vê</legend>
      <div class="choice-tiles" role="radiogroup" aria-label="Quem vê o relógio">
        <button v-for="v in SEES" :key="v.id" type="button" role="radio" class="choice-tile" :class="{ on: f.visibility === v.id }" :aria-checked="f.visibility === v.id" @click="f.visibility = v.id">
          <b>{{ v.name }}</b><small>{{ v.hint }}</small>
        </button>
      </div>
      <div v-if="f.visibility === 'players'" class="player-picks">
        <label v-for="p in players" :key="p.user_id" class="check"><input type="checkbox" :checked="f.player_ids.includes(p.user_id)" @change="togglePlayer(p.user_id)" /> {{ p.name }}</label>
        <p v-if="!players.length" class="muted small">Nenhum jogador na campanha.</p>
        <p v-else-if="!f.player_ids.length" class="muted small">Marque pelo menos um jogador.</p>
      </div>
    </fieldset>

    <template #actions>
      <button v-if="clock" type="button" class="link danger" @click="remove">Remover relógio</button>
      <span class="grow" />
      <button type="button" class="ghost" @click="emit('close')">Cancelar</button>
      <button type="submit" class="primary" :disabled="!valid">{{ clock ? 'Salvar' : 'Criar relógio' }}</button>
    </template>
  </ActionDialog>
</template>

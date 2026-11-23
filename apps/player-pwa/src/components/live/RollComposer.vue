<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { describeDice } from '../../live/dice'
import type { SheetField } from '../../live/sheet'
import type { CountMode, DiceChoice, Reading } from '../../live/types'

/**
 * The master's roll request (SPEC-MVP-002 D3): the combination comes from the sheet (selected
 * fields), the master chooses the dice, the reading and the target. Presets cover the common
 * shapes; everything stays editable. The pool shown here is a preview: the server recomputes it
 * from the official sheet.
 */
const props = defineProps<{ subjectName: string; subjectKind: 'character' | 'npc'; fields: SheetField[]; selected: string[]; busy?: boolean }>()
const emit = defineEmits<{ toggle: [path: string]; submit: [input: { paths: string[]; modifier: number; dice: DiceChoice; attempts: number; secret: boolean; allows_choice: boolean; label: string }] }>()

const PRESETS: { id: string; name: string; dice: Omit<DiceChoice, 'target'> & { target: number | null } }[] = [
  { id: 'pool', name: 'Parada de d10', dice: { faces: 10, count_mode: 'pool', reading: 'successes', threshold: 6, target: 2 } },
  { id: 'd20', name: 'd20 + bônus', dice: { faces: 20, count_mode: 'fixed', count: 1, reading: 'sum', threshold: null, target: 15 } },
  { id: '2d6', name: '2d6 + bônus', dice: { faces: 6, count_mode: 'fixed', count: 2, reading: 'sum', threshold: null, target: 10 } },
  { id: 'd6pool', name: 'Parada de d6 (maior)', dice: { faces: 6, count_mode: 'pool', reading: 'highest', threshold: null, target: 6 } },
]
const FACES = [4, 6, 8, 10, 12, 20, 100]
const f = reactive({ preset: 'pool', faces: 10, count_mode: 'pool' as CountMode, count: 1, reading: 'successes' as Reading, threshold: 6, target: 2 as number | null, modifier: 0, attempts: 1, secret: props.subjectKind === 'npc', choice: false, label: '' })
watch(() => props.subjectKind, k => { f.secret = k === 'npc' })

function preset(id: string): void {
  const p = PRESETS.find(x => x.id === id)!
  Object.assign(f, { preset: id, faces: p.dice.faces, count_mode: p.dice.count_mode, count: p.dice.count ?? 1, reading: p.dice.reading, threshold: p.dice.threshold ?? Math.floor(p.dice.faces / 2) + 1, target: p.dice.target })
}
const sum = computed(() => props.fields.filter(x => props.selected.includes(x.path)).reduce((a, x) => a + (typeof x.value === 'number' ? x.value : 0), 0) + f.modifier)
const preview = computed(() => {
  const count = f.count_mode === 'pool' ? Math.max(1, Math.min(f.faces === 100 ? 6 : 12, sum.value)) : f.count
  return describeDice({ faces: f.faces, reading: f.reading, threshold: f.threshold, target: f.target, bonus: f.count_mode === 'fixed' ? sum.value : 0 }, count)
})
const comboLabel = computed(() => props.fields.filter(x => props.selected.includes(x.path)).map(x => x.label).join(' + '))

function submit(): void {
  emit('submit', {
    paths: [...props.selected],
    modifier: f.modifier,
    dice: { faces: f.faces, count_mode: f.count_mode, ...(f.count_mode === 'fixed' ? { count: f.count } : {}), reading: f.reading, threshold: f.reading === 'successes' ? f.threshold : null, target: f.target },
    attempts: f.attempts,
    secret: f.secret,
    allows_choice: f.choice && f.count_mode === 'pool',
    label: f.label.trim() || comboLabel.value,
  })
}
</script>

<template>
  <form class="composer" @submit.prevent="submit">
    <div class="composer-head">
      <p class="eyebrow">{{ subjectKind === 'npc' ? 'Rolar pelo PdM' : 'Pedir rolagem' }}</p>
      <strong>{{ subjectName }}</strong>
    </div>
    <fieldset class="combo">
      <legend>Combinação <span class="muted small">(toque em atributo e perícia)</span></legend>
      <div class="combo-chips">
        <button v-for="x in fields" :key="x.path" type="button" class="chip" :class="{ on: selected.includes(x.path) }" :aria-pressed="selected.includes(x.path)" @click="emit('toggle', x.path)">{{ x.label }} <b>{{ x.value }}</b></button>
      </div>
    </fieldset>
    <div class="row">
      <label class="mini">Modificador
        <span class="stepper"><button type="button" class="step" aria-label="Modificador menos 1" @click="f.modifier = Math.max(-20, f.modifier - 1)">−</button><output>{{ f.modifier > 0 ? '+' : '' }}{{ f.modifier }}</output><button type="button" class="step" aria-label="Modificador mais 1" @click="f.modifier = Math.min(20, f.modifier + 1)">+</button></span>
      </label>
      <label class="mini">Rótulo <input v-model="f.label" maxlength="120" :placeholder="comboLabel || 'Ex.: Escalar a ponte'" /></label>
    </div>
    <fieldset class="dice-choice">
      <legend>Dados (o jogador não pode mudar)</legend>
      <div class="segmented small-seg" role="radiogroup" aria-label="Modelo">
        <button v-for="p in PRESETS" :key="p.id" type="button" class="seg" :class="{ on: f.preset === p.id }" :aria-pressed="f.preset === p.id" @click="preset(p.id)">{{ p.name }}</button>
      </div>
      <div class="row wrap">
        <label class="mini">Dado <select v-model.number="f.faces"><option v-for="n in FACES" :key="n" :value="n">d{{ n }}</option></select></label>
        <label class="mini">Quantidade <select v-model="f.count_mode"><option value="pool">da combinação</option><option value="fixed">fixa</option></select></label>
        <label v-if="f.count_mode === 'fixed'" class="mini">Nº de dados <input v-model.number="f.count" type="number" min="1" :max="f.faces === 100 ? 6 : 12" /></label>
        <label class="mini">Leitura <select v-model="f.reading"><option value="successes">sucessos</option><option value="sum">soma</option><option value="highest">maior</option></select></label>
        <label v-if="f.reading === 'successes'" class="mini">Sucesso em <input v-model.number="f.threshold" type="number" min="1" :max="f.faces" /></label>
        <label class="mini">{{ f.reading === 'successes' ? 'Precisa de' : 'Alvo' }} <input v-model.number="f.target" type="number" min="-50" max="500" /></label>
        <label v-if="subjectKind === 'character'" class="mini">Tentativas <input v-model.number="f.attempts" type="number" min="1" max="5" /></label>
      </div>
      <div class="row wrap toggles">
        <label class="check"><input v-model="f.secret" type="checkbox" /> Secreta (só eu{{ subjectKind === 'character' ? ' e o jogador' : '' }})</label>
        <label v-if="subjectKind === 'character' && f.count_mode === 'pool'" class="check"><input v-model="f.choice" type="checkbox" /> Jogador escolhe quantos dados</label>
      </div>
    </fieldset>
    <p class="formula" aria-live="polite">{{ comboLabel || 'Sem combinação' }} → <strong>{{ preview }}</strong></p>
    <button type="submit" class="primary" :disabled="busy || (subjectKind === 'character' && selected.length === 0)">{{ subjectKind === 'npc' ? 'Rolar agora' : `Pedir a ${subjectName}` }}</button>
  </form>
</template>

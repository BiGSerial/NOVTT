<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { describeDice } from '../../live/dice'
import type { SheetField } from '../../live/sheet'
import type { CountMode, Reading } from '../../live/types'
import { useMaster, type Subject } from './context'

/**
 * The roll tray (SPEC-MVP-002 D3, owner 2026-11-22): the combination comes from the sheet, the
 * master chooses the dice. By default a roll stays between the master and the player; "à mesa"
 * shows it to everyone. Works for one subject or a whole selected group.
 */
const props = defineProps<{ targets: Subject[]; fields: SheetField[] }>()
const m = useMaster()
const PRESETS = [
  { id: 'pool', name: 'Parada d10', faces: 10, count_mode: 'pool' as CountMode, count: 1, reading: 'successes' as Reading, threshold: 6, target: 2 as number | null },
  { id: 'd20', name: 'd20 + bônus', faces: 20, count_mode: 'fixed' as CountMode, count: 1, reading: 'sum' as Reading, threshold: 11, target: 15 as number | null },
  { id: '2d6', name: '2d6 + bônus', faces: 6, count_mode: 'fixed' as CountMode, count: 2, reading: 'sum' as Reading, threshold: 4, target: 10 as number | null },
  { id: 'd6', name: 'Parada d6 (maior)', faces: 6, count_mode: 'pool' as CountMode, count: 1, reading: 'highest' as Reading, threshold: 4, target: 6 as number | null },
]
const FACES = [4, 6, 8, 10, 12, 20, 100]
const f = reactive({ preset: 'pool', faces: 10, count_mode: 'pool' as CountMode, count: 1, reading: 'successes' as Reading, threshold: 6, target: 2 as number | null, modifier: 0, attempts: 1, toTable: false, choice: false, label: '', advanced: false })
const onlyNpcs = computed(() => props.targets.every(x => x.kind === 'npc'))
const pcs = computed(() => props.targets.filter(x => x.kind === 'character'))
watch(() => props.targets.map(x => x.id).join(), () => { f.toTable = false })

function preset(id: string): void {
  const p = PRESETS.find(x => x.id === id)!
  Object.assign(f, { preset: id, faces: p.faces, count_mode: p.count_mode, count: p.count, reading: p.reading, threshold: p.threshold, target: p.target })
}
const chosen = computed(() => props.fields.filter(x => m.paths.value.includes(x.path)))
const sum = computed(() => chosen.value.reduce((a, x) => a + (typeof x.value === 'number' ? x.value : 0), 0) + f.modifier)
const preview = computed(() => {
  const count = f.count_mode === 'pool' ? Math.max(1, Math.min(f.faces === 100 ? 6 : 12, sum.value)) : f.count
  return describeDice({ faces: f.faces, reading: f.reading, threshold: f.threshold, target: f.target, bonus: f.count_mode === 'fixed' ? sum.value : 0 }, count)
})
const comboLabel = computed(() => chosen.value.map(x => x.label).join(' + '))
const who = computed(() => {
  if (props.targets.length === 1) return m.byId(props.targets[0].id)?.name ?? ''
  return `${props.targets.length} selecionados`
})
const collapsed = computed(() => !onlyNpcs.value && !m.paths.value.length && !m.suggestionId.value)
const canSend = computed(() => props.targets.length > 0 && (onlyNpcs.value || m.paths.value.length > 0) && m.s.value.session.status === 'active')
const audience = computed(() => {
  if (onlyNpcs.value) return f.toTable ? 'Todos verão os dados (sem os números do PdM)' : 'Só você verá; dá para revelar depois'
  if (f.toTable) return 'Toda a mesa verá os dados e o resultado'
  return pcs.value.length === 1 ? `Só você e ${m.byId(pcs.value[0].id)?.sub || 'o jogador'} verão` : 'Cada jogador vê só a própria rolagem'
})

async function send(): Promise<void> {
  await m.request(props.targets, {
    paths: [...m.paths.value],
    modifier: f.modifier,
    dice: { faces: f.faces, count_mode: f.count_mode, ...(f.count_mode === 'fixed' ? { count: f.count } : {}), reading: f.reading, threshold: f.reading === 'successes' ? f.threshold : null, target: f.target },
    attempts: f.attempts,
    secret: !f.toTable,
    allows_choice: f.choice && f.count_mode === 'pool',
    label: f.label.trim() || comboLabel.value || `d${f.faces}`,
  }, m.suggestionId.value)
  m.paths.value = []
  m.suggestionId.value = null
  f.label = ''
  f.modifier = 0
}
</script>

<template>
  <div v-if="collapsed" class="roll-tray collapsed" aria-label="Bandeja de rolagem">
    <span class="die-icon" aria-hidden="true">⬢</span>
    <span>Toque em um <strong>atributo</strong> e uma <strong>perícia</strong> na ficha para pedir uma rolagem a <strong>{{ who }}</strong>.</span>
  </div>
  <form v-else class="roll-tray" aria-label="Bandeja de rolagem" @submit.prevent="send">
    <div class="tray-combo">
      <p class="tray-kicker">{{ onlyNpcs ? 'Rolar por' : 'Pedir rolagem a' }} <strong>{{ who }}</strong></p>
      <p class="tray-formula">
        <template v-if="chosen.length"><span v-for="x in chosen" :key="x.path" class="combo-pill">{{ x.label }} <b>{{ x.value }}</b><button type="button" :aria-label="`Tirar ${x.label}`" @click="m.togglePath(x.path)">×</button></span></template>
        <span v-else class="muted">Toque em um atributo e uma perícia na ficha</span>
        <span class="stepper" aria-label="Modificador"><button type="button" class="step" aria-label="Modificador menos 1" @click="f.modifier = Math.max(-20, f.modifier - 1)">−</button><output>{{ f.modifier > 0 ? '+' : '' }}{{ f.modifier }}</output><button type="button" class="step" aria-label="Modificador mais 1" @click="f.modifier = Math.min(20, f.modifier + 1)">+</button></span>
        <span class="arrow">→</span><strong class="tray-dice">{{ preview }}</strong>
      </p>
      <p v-if="m.suggestionId.value" class="muted small">A partir da sugestão do jogador: ajuste os dados e envie.</p>
    </div>
    <div class="tray-row">
      <div class="seg-tabs small" role="group" aria-label="Modelo de dados">
        <button v-for="p in PRESETS" :key="p.id" type="button" :class="{ on: f.preset === p.id }" :aria-pressed="f.preset === p.id" @click="preset(p.id)">{{ p.name }}</button>
      </div>
      <label class="mini">{{ f.reading === 'successes' ? 'Precisa de' : 'Alvo' }} <input v-model.number="f.target" type="number" min="-50" max="500" /></label>
      <button type="button" class="link" :aria-expanded="f.advanced" @click="f.advanced = !f.advanced">{{ f.advanced ? 'menos opções' : 'mais opções' }}</button>
    </div>
    <div v-if="f.advanced" class="tray-row wrap">
      <label class="mini">Dado <select v-model.number="f.faces"><option v-for="n in FACES" :key="n" :value="n">d{{ n }}</option></select></label>
      <label class="mini">Quantidade <select v-model="f.count_mode"><option value="pool">da combinação</option><option value="fixed">fixa</option></select></label>
      <label v-if="f.count_mode === 'fixed'" class="mini">Nº <input v-model.number="f.count" type="number" min="1" :max="f.faces === 100 ? 6 : 12" /></label>
      <label class="mini">Leitura <select v-model="f.reading"><option value="successes">sucessos</option><option value="sum">soma</option><option value="highest">maior</option></select></label>
      <label v-if="f.reading === 'successes'" class="mini">Sucesso em <input v-model.number="f.threshold" type="number" min="1" :max="f.faces" /></label>
      <label v-if="!onlyNpcs" class="mini">Tentativas <input v-model.number="f.attempts" type="number" min="1" max="5" /></label>
      <label class="mini grow">Rótulo <input v-model="f.label" maxlength="120" :placeholder="comboLabel || 'Ex.: Escalar a ponte'" /></label>
      <label v-if="!onlyNpcs && f.count_mode === 'pool'" class="check"><input v-model="f.choice" type="checkbox" /> Jogador escolhe quantos dados</label>
    </div>
    <div class="tray-send">
      <div class="seg-tabs vis" role="radiogroup" aria-label="Quem vê">
        <button type="button" role="radio" :aria-checked="!f.toTable" :class="{ on: !f.toTable }" @click="f.toTable = false">🔒 {{ onlyNpcs ? 'Só eu' : 'Entre nós' }}</button>
        <button type="button" role="radio" :aria-checked="f.toTable" :class="{ on: f.toTable }" @click="f.toTable = true">👁 À mesa</button>
      </div>
      <span class="muted small tray-aud">{{ audience }}</span>
      <button type="submit" class="primary big" :disabled="!canSend || m.busy.value">{{ onlyNpcs ? 'Rolar agora' : targets.length > 1 ? `Pedir a ${pcs.length}` : 'Pedir rolagem' }}</button>
    </div>
  </form>
</template>

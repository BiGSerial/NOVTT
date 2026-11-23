<script setup lang="ts">
import { computed, ref } from 'vue'
import Portrait from '../../components/live/Portrait.vue'
import { readPointer } from '../../live/sheet'
import RollTray from './RollTray.vue'
import { useMaster } from './context'

/**
 * A selected group side by side: health, conditions and the same numbers in one table, with bulk
 * actions (damage, healing, a condition for everyone, one roll request for all, a whisper to all).
 */
const m = useMaster()
const list = computed(() => m.selection.value.map(x => m.byId(x.id)).filter((e): e is NonNullable<typeof e> => !!e))
const first = computed(() => (list.value[0] ? m.sheets.byId.get(list.value[0].id) : undefined))
const columns = computed(() => first.value?.numeric ?? [])
const value = (id: string, path: string) => { const raw = m.sheets.byId.get(id)?.raw; return raw ? readPointer(raw, path) : '…' }
const amount = ref(1)
const condition = ref('Ferido')
const whisperText = ref('')
const players = computed(() => list.value.filter(e => e.kind === 'character' && e.playerUserId))
async function whisperAll(): Promise<void> {
  const body = whisperText.value.trim()
  if (!body) return
  let n = 0
  for (const p of players.value) if (await m.whisper(p.playerUserId!, body)) n++
  if (n) m.table.toast(`Sussurro enviado a ${n} jogador${n > 1 ? 'es' : ''}`)
  whisperText.value = ''
}
const COMMON = ['Ferido', 'Caído', 'Assustado', 'Exausto', 'Escondido', 'Inspirado', 'Envenenado', 'Preso']
</script>

<template>
  <article class="party-board" aria-label="Grupo selecionado">
    <header class="party-head">
      <div class="party-faces"><Portrait v-for="e in list" :key="e.id" :name="e.name" :image-id="e.portrait" :live="e.live" :size="40" :kind="e.kind" /></div>
      <h2 class="char-name">{{ list.length }} selecionados</h2>
      <button type="button" class="link" @click="m.clearSelection()">Limpar seleção</button>
    </header>

    <div class="bulk">
      <div class="bulk-group">
        <span class="bulk-label">Vida</span>
        <span class="stepper"><button type="button" class="step" aria-label="Menos" @click="amount = Math.max(1, amount - 1)">−</button><output>{{ amount }}</output><button type="button" class="step" aria-label="Mais" @click="amount = Math.min(50, amount + 1)">+</button></span>
        <button type="button" class="small-btn danger-btn" :disabled="m.busy.value" @click="m.adjustMany(m.selection.value, -amount)">Dano −{{ amount }}</button>
        <button type="button" class="small-btn" :disabled="m.busy.value" @click="m.adjustMany(m.selection.value, amount)">Cura +{{ amount }}</button>
      </div>
      <div class="bulk-group">
        <span class="bulk-label">Condição</span>
        <select v-model="condition" aria-label="Condição"><option v-for="c in COMMON" :key="c">{{ c }}</option></select>
        <button type="button" class="small-btn" :disabled="m.busy.value" @click="m.conditionMany(m.selection.value, condition, true)">Aplicar</button>
        <button type="button" class="link" :disabled="m.busy.value" @click="m.conditionMany(m.selection.value, condition, false)">Tirar</button>
      </div>
      <form v-if="players.length" class="bulk-group grow" @submit.prevent="whisperAll">
        <span class="bulk-label">Sussurro</span>
        <input v-model="whisperText" maxlength="2000" :placeholder="`Para ${players.length} jogador(es), cada um recebe só o seu`" aria-label="Sussurro para o grupo" />
        <button type="submit" class="small-btn" :disabled="!whisperText.trim()">Enviar</button>
      </form>
      <div class="bulk-group">
        <span class="bulk-label">Iniciativa</span>
        <button type="button" class="small-btn" @click="m.initFrom(m.selection.value)">Montar com estes</button>
      </div>
    </div>

    <div class="party-table-wrap">
      <table class="party-table">
        <thead>
          <tr><th scope="col">Quem</th><th scope="col">Vida</th><th scope="col">Condições</th>
            <th v-for="c in columns" :key="c.path" scope="col"><button type="button" class="col-pick" :class="{ on: m.paths.value.includes(c.path) }" :aria-pressed="m.paths.value.includes(c.path)" :title="'Usar na rolagem'" @click="m.togglePath(c.path)">{{ c.label }}</button></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in list" :key="e.id" :class="{ npc: e.kind === 'npc' }">
            <th scope="row"><button type="button" class="link" @click="m.select(e.kind, e.id)">{{ e.name }}</button></th>
            <td><template v-if="e.live?.resources?.[0]">{{ e.live.resources[0].current }}/{{ e.live.resources[0].max }}</template><template v-else>—</template></td>
            <td class="small">{{ e.live?.conditions?.join(', ') || '—' }}</td>
            <td v-for="c in columns" :key="c.path" :class="{ hot: m.paths.value.includes(c.path) }">{{ value(e.id, c.path) ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="muted small">Toque no nome de uma coluna (atributo e perícia) para montar a rolagem de todos.</p>
    <RollTray :targets="m.selection.value" :fields="columns" />
  </article>
</template>

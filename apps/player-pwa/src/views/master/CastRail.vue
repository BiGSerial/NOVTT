<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Portrait from '../../components/live/Portrait.vue'
import { useMaster, type CastEntry } from './context'

/**
 * The cast: every player character and NPC as a token with portrait, health ring, conditions and
 * pending rolls. Click selects one; Ctrl/⌘/Shift-click or the check box builds a group for bulk
 * actions. Filters keep big tables (5 PCs, 10+ NPCs) manageable: group, in scene, reserve.
 */
const m = useMaster()
const filter = ref<'all' | 'party' | 'scene' | 'npcs'>('all')
const q = ref('')
const match = (e: CastEntry) => !q.value || e.name.toLowerCase().includes(q.value.toLowerCase()) || e.sub.toLowerCase().includes(q.value.toLowerCase())
const party = computed(() => m.cast.value.filter(e => e.kind === 'character' && match(e)))
const scene = computed(() => m.cast.value.filter(e => e.kind === 'npc' && m.inScene.value.includes(e.id) && match(e)))
const reserve = computed(() => m.cast.value.filter(e => e.kind === 'npc' && !m.inScene.value.includes(e.id) && match(e)))
const show = (g: 'party' | 'scene' | 'npcs') => filter.value === 'all' || filter.value === g || (g === 'scene' && filter.value === 'npcs')

// A player's request rides on their token: plain click opens the sheet on what they asked for.
const asksOf = (id: string) => m.s.value.suggestions.filter(sg => sg.campaign_character_id === id)
const asking = computed(() => m.s.value.suggestions.length)
// Read the asking sheets ahead so the request shows "Força", not the raw path.
watch(() => m.s.value.suggestions.map(sg => sg.campaign_character_id).join(), () => {
  for (const sg of m.s.value.suggestions) {
    const e = m.byId(sg.campaign_character_id)
    if (e && !m.sheets.byId.has(e.id)) m.sheets.character(e.id, e.preset).catch(() => undefined)
  }
}, { immediate: true })
function askTitle(id: string): string {
  return asksOf(id).map(sg => `Pede ${m.pathLabels(id, sg.paths)}${sg.note ? ` — “${sg.note}”` : ''}`).join('\n')
}
function click(e: CastEntry, ev: MouseEvent | KeyboardEvent): void {
  const additive = ev.ctrlKey || ev.metaKey || ev.shiftKey
  const ask = additive ? undefined : asksOf(e.id)[0]
  if (ask) m.fromSuggestion(ask)
  else m.select(e.kind, e.id, additive)
}
const turnId = computed(() => m.initiative.value[m.s.value.session.initiative?.turn ?? 0]?.id)
const pending = (id: string) => m.pendingFor(id).length
const groups = computed(() => [
  { id: 'party' as const, title: 'Grupo', list: party.value, all: () => m.selectMany(party.value.map(e => ({ kind: e.kind, id: e.id }))) },
  { id: 'scene' as const, title: 'Em cena', list: scene.value, all: () => m.selectMany(scene.value.map(e => ({ kind: e.kind, id: e.id }))) },
  { id: 'npcs' as const, title: 'Reserva', list: reserve.value, all: null },
])
</script>

<template>
  <aside class="cast-rail" aria-label="Elenco">
    <div class="rail-head">
      <div class="seg-tabs" role="tablist" aria-label="Filtro do elenco">
        <button type="button" role="tab" :aria-selected="filter === 'all'" :class="{ on: filter === 'all' }" @click="filter = 'all'">Todos</button>
        <button type="button" role="tab" :aria-selected="filter === 'party'" :class="{ on: filter === 'party' }" @click="filter = 'party'">Grupo {{ party.length }}<span v-if="asking" class="tab-ask" :title="`${asking} pedido(s) de jogador`" /></button>
        <button type="button" role="tab" :aria-selected="filter === 'npcs'" :class="{ on: filter === 'npcs' }" @click="filter = 'npcs'">PdMs {{ scene.length + reserve.length }}</button>
      </div>
      <input v-model="q" type="search" class="rail-search" placeholder="Buscar no elenco" aria-label="Buscar no elenco" />
    </div>


    <div class="rail-scroll">
      <template v-for="g in groups" :key="g.id">
        <section v-if="show(g.id) && (g.list.length || g.id !== 'scene')" class="rail-group">
          <header class="rail-title">
            <span>{{ g.title }} <small>{{ g.list.length }}</small></span>
            <button v-if="g.all && g.list.length > 1" type="button" class="link" @click="g.all()">selecionar todos</button>
          </header>
          <ul class="tokens">
            <li v-for="e in g.list" :key="e.id">
              <div
                class="token" :class="{ on: m.selected(e.id), focus: m.focus.value?.id === e.id, turn: turnId === e.id, low: m.spotlightLow.value.has(e.id), npc: e.kind === 'npc', asking: asksOf(e.id).length > 0 }"
                role="button" tabindex="0" :aria-pressed="m.selected(e.id)"
                @click="click(e, $event)" @keydown.enter.prevent="click(e, $event)" @keydown.space.prevent="click(e, $event)"
              >
                <input
                  type="checkbox" class="token-check" :checked="m.selected(e.id)" :aria-label="`Incluir ${e.name} no grupo selecionado`"
                  @click.stop="m.select(e.kind, e.id, true)" @keydown.stop
                />
                <Portrait :name="e.name" :image-id="e.portrait" :live="e.live" :size="46" :kind="e.kind" />
                <span class="token-text">
                  <strong>{{ e.name }}</strong>
                  <small>{{ e.kind === 'character' ? e.sub : (e.live?.resources?.[0] ? `${e.live.resources[0].label} ${e.live.resources[0].current}/${e.live.resources[0].max}` : 'PdM') }}</small>
                  <span v-if="e.live?.conditions?.length" class="token-conds">{{ e.live.conditions.slice(0, 3).join(' · ') }}<template v-if="e.live.conditions.length > 3"> +{{ e.live.conditions.length - 3 }}</template></span>
                </span>
                <span class="token-flags">
                  <span v-if="asksOf(e.id).length" class="flag ask" :title="askTitle(e.id)">✋ pedido<template v-if="asksOf(e.id).length > 1"> {{ asksOf(e.id).length }}</template></span>
                  <span v-if="pending(e.id)" class="flag dice" :title="`${pending(e.id)} rolagem aguardando`">🎲</span>
                  <span v-if="turnId === e.id" class="flag turn" title="Vez desta pessoa">vez</span>
                  <span v-if="m.spotlightLow.value.has(e.id)" class="flag spot" title="Teve menos holofote">★</span>
                </span>
                <button
                  v-if="e.kind === 'npc'" type="button" class="token-scene" :aria-pressed="m.inScene.value.includes(e.id)"
                  :title="m.inScene.value.includes(e.id) ? 'Tirar da cena' : 'Trazer para a cena'" @click.stop="m.toggleInScene(e.id)"
                >{{ m.inScene.value.includes(e.id) ? '−' : '+' }}</button>
              </div>
            </li>
            <li v-if="!g.list.length" class="muted small rail-empty">{{ g.id === 'npcs' ? 'Todos os PdMs estão em cena.' : 'Ninguém aqui.' }}</li>
          </ul>
        </section>
      </template>
    </div>
    <p class="rail-hint">Clique para abrir · <kbd>Ctrl</kbd>+clique para agrupar</p>
  </aside>
</template>

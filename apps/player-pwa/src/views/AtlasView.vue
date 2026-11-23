<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import { campaigns, world } from '../live/api'
import { go, me } from '../live/user'
import { applyTheme, loadTheme } from '../live/theme'
import type { AtlasEntry, AtlasKind } from '../live/types'

/**
 * The campaign world (SPEC-MVP-002 LT-07): places, people, factions, secrets and story threads,
 * linked to each other, with a timeline of how each evolved session by session. Players see the
 * public summaries of what the master marked as known.
 */
const props = defineProps<{ campaignId: string }>()
const entries = ref<AtlasEntry[]>([])
const master = ref(false)
const kind = ref<AtlasKind | 'all'>('all')
const selectedId = ref<string | null>(null)
const draft = reactive<Partial<AtlasEntry>>({})
const note = ref('')
const status = ref('')
const KINDS: { id: AtlasKind; name: string; icon: string }[] = [
  { id: 'place', name: 'Lugares', icon: '⌂' }, { id: 'person', name: 'Pessoas', icon: '☺' }, { id: 'faction', name: 'Facções', icon: '⚑' },
  { id: 'secret', name: 'Segredos', icon: '✶' }, { id: 'thread', name: 'Fios da trama', icon: '∿' },
]
const shown = computed(() => entries.value.filter(e => kind.value === 'all' || e.kind === kind.value))
const selected = computed(() => entries.value.find(e => e.id === selectedId.value) ?? null)
const linked = computed(() => entries.value.filter(e => selected.value?.links?.includes(e.id) || e.links?.includes(selected.value?.id ?? '')))

onMounted(async () => {
  if (!me.value) return go('#/')
  applyTheme(loadTheme(props.campaignId))
  master.value = (await campaigns.list()).data.find(c => c.id === props.campaignId)?.role === 'master'
  entries.value = (await world.atlas(props.campaignId)).data
  if (entries.value[0]) pick(entries.value[0])
})
function pick(e: AtlasEntry): void {
  selectedId.value = e.id
  Object.assign(draft, { title: e.title, summary: e.summary, details: e.details ?? '', known_to_players: e.known_to_players ?? false, thread_status: e.thread_status, kind: e.kind, links: [...(e.links ?? [])] })
  status.value = ''
}
async function create(k: AtlasKind): Promise<void> {
  const e = await world.createEntry(props.campaignId, { kind: k, title: `Novo: ${KINDS.find(x => x.id === k)!.name.toLowerCase()}`, summary: '' })
  entries.value.push(e)
  pick(e)
}
async function save(): Promise<void> {
  const e = selected.value
  if (!e || e.revision === undefined) return
  try {
    const saved = await world.updateEntry(e.id, e.revision, { title: draft.title, summary: draft.summary, details: draft.details, known_to_players: draft.known_to_players, thread_status: draft.kind === 'thread' ? draft.thread_status : undefined, links: draft.links })
    entries.value = entries.value.map(x => (x.id === saved.id ? saved : x))
    status.value = 'Salvo'
  } catch (err) {
    status.value = err instanceof ApiError && err.status === 409 ? 'Mudou em outra janela: recarregue.' : 'Não foi possível salvar.'
  }
}
async function addNote(): Promise<void> {
  const e = selected.value
  if (!e || !note.value.trim()) return
  const item = await world.addTimeline(e.id, note.value.trim())
  e.timeline = [...(e.timeline ?? []), item]
  note.value = ''
}
function toggleLink(id: string): void {
  draft.links = draft.links?.includes(id) ? draft.links.filter(x => x !== id) : [...(draft.links ?? []), id]
}
const icon = (k: AtlasKind) => KINDS.find(x => x.id === k)?.icon ?? ''
const date = (iso: string) => new Date(iso).toLocaleDateString('pt-BR')
</script>

<template>
  <main class="page wide atlas">
    <header class="topbar">
      <a href="#/campanhas" class="brand-link"><BrandMark /></a>
      <h1>{{ master ? 'Atlas do mundo' : 'O que sabemos' }}</h1>
      <span class="spacer" />
      <a class="button" :href="`#/mesa/${campaignId}`">Ir para a mesa</a>
    </header>
    <div class="segmented-row" role="group" aria-label="Filtrar">
      <button type="button" :class="{ on: kind === 'all' }" @click="kind = 'all'">Tudo</button>
      <button v-for="k in KINDS" :key="k.id" type="button" :class="{ on: kind === k.id }" @click="kind = k.id">{{ k.icon }} {{ k.name }}</button>
    </div>
    <div class="atlas-grid">
      <nav class="atlas-list" aria-label="Verbetes">
        <button v-for="e in shown" :key="e.id" type="button" class="binder-item" :class="[e.kind, { on: selectedId === e.id }]" @click="pick(e)">
          <span aria-hidden="true">{{ icon(e.kind) }}</span> {{ e.title }}
          <span v-if="e.kind === 'thread'" class="pill">{{ e.thread_status === 'resolved' ? 'resolvido' : e.thread_status === 'advancing' ? 'avançando' : 'aberto' }}</span>
          <span v-if="master && !e.known_to_players" class="pill secret">só o mestre</span>
        </button>
        <p v-if="!shown.length" class="muted small">Nada aqui ainda.</p>
        <div v-if="master" class="new-entry"><p class="eyebrow">Criar</p><button v-for="k in KINDS" :key="k.id" type="button" class="chip" @click="create(k.id)">{{ k.icon }} {{ k.name.replace(/s$/, '') }}</button></div>
      </nav>
      <article v-if="selected" class="atlas-entry">
        <template v-if="master">
          <label>Título <input v-model="draft.title" maxlength="120" /></label>
          <label>Resumo público <textarea v-model="draft.summary" rows="2" maxlength="500" /></label>
          <label>Só o mestre <textarea v-model="draft.details" rows="5" maxlength="8000" /></label>
          <div class="row wrap">
            <label v-if="selected.kind !== 'secret'" class="check"><input v-model="draft.known_to_players" type="checkbox" /> Os jogadores conhecem</label>
            <label v-if="selected.kind === 'thread'" class="mini">Fio <select v-model="draft.thread_status"><option value="open">aberto</option><option value="advancing">avançando</option><option value="resolved">resolvido</option></select></label>
          </div>
          <fieldset class="links"><legend>Ligado a</legend>
            <button v-for="e in entries.filter(x => x.id !== selected!.id)" :key="e.id" type="button" class="chip" :class="{ on: draft.links?.includes(e.id) }" :aria-pressed="draft.links?.includes(e.id)" @click="toggleLink(e.id)">{{ icon(e.kind) }} {{ e.title }}</button>
          </fieldset>
          <div class="save-bar"><span class="muted" role="status">{{ status }}</span><button type="button" class="primary" @click="save">Salvar</button></div>
          <h2>Como evoluiu</h2>
          <ol class="timeline">
            <li v-for="t in selected.timeline ?? []" :key="t.id"><time :datetime="t.created_at">{{ date(t.created_at) }}</time> {{ t.note }} <span v-if="t.session_id" class="pill">na sessão</span></li>
            <li v-if="!(selected.timeline?.length)" class="muted small">Registre aqui o que mudou a cada sessão.</li>
          </ol>
          <form class="row" @submit.prevent="addNote"><input v-model="note" maxlength="500" placeholder="O que mudou?" aria-label="Evolução" class="grow" /><button type="submit" class="small-btn">Registrar</button></form>
        </template>
        <template v-else>
          <h2><span aria-hidden="true">{{ icon(selected.kind) }}</span> {{ selected.title }}</h2>
          <p>{{ selected.summary }}</p>
        </template>
        <h3 v-if="master && linked.length">Ligações</h3>
        <ul v-if="master" class="links-list"><li v-for="e in linked" :key="e.id"><button type="button" class="link" @click="pick(e)">{{ icon(e.kind) }} {{ e.title }}</button></li></ul>
      </article>
    </div>
  </main>
</template>

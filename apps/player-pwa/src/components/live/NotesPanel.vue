<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { ApiError } from '../../api/client'
import { table as tableApi } from '../../live/api'
import type { TableState } from '../../live/table'
import type { MasterNote } from '../../live/types'

/**
 * Private master notes (SPEC-002 MW-08): quick capture, tags, pin, debounced autosave with the
 * known revision. A conflict (another window saved first) keeps the local text and says so,
 * never overwriting silently.
 */
const props = defineProps<{ campaignId: string; notes: MasterNote[]; state: TableState }>()
const emit = defineEmits<{ error: [e: unknown] }>()
const quick = ref('')
const filter = ref('')
const drafts = reactive<Record<string, string>>({})
const status = reactive<Record<string, 'saving' | 'saved' | 'conflict'>>({})
const timers = new Map<string, ReturnType<typeof setTimeout>>()
onBeforeUnmount(() => timers.forEach(clearTimeout))

const tags = computed(() => [...new Set(props.notes.flatMap(n => n.tags))].sort())
const shown = computed(() => props.notes.filter(n => !filter.value || n.tags.includes(filter.value)))
const bodyOf = (n: MasterNote) => drafts[n.id] ?? n.body

/** "#pdm Nome: Velha Corva" → tags [pdm], body "Nome: Velha Corva". */
function parse(text: string): { body: string; tags: string[] } {
  const found = [...text.matchAll(/#([\p{L}\p{N}_-]{1,24})/gu)].map(m => m[1].toLowerCase())
  return { body: text.replace(/#[\p{L}\p{N}_-]{1,24}/gu, '').replace(/\s+/g, ' ').trim() || text.trim(), tags: [...new Set(found)].slice(0, 8) }
}

async function add(): Promise<void> {
  const text = quick.value.trim()
  if (!text) return
  const { body, tags } = parse(text)
  try {
    props.state.local.note(await tableApi.createNote(props.campaignId, body, tags))
    quick.value = ''
  } catch (e) { emit('error', e) }
}

function edit(n: MasterNote, text: string): void {
  drafts[n.id] = text
  clearTimeout(timers.get(n.id))
  timers.set(n.id, setTimeout(() => void save(n.id), 800))
}

async function save(id: string, patch: Partial<Pick<MasterNote, 'pinned'>> = {}): Promise<void> {
  const n = props.notes.find(x => x.id === id)
  if (!n) return
  status[id] = 'saving'
  try {
    const saved = await tableApi.updateNote(id, n.revision, { ...(drafts[id] !== undefined ? { body: drafts[id] } : {}), ...patch })
    props.state.local.note(saved)
    if (drafts[id] === saved.body) delete drafts[id]
    status[id] = 'saved'
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) status[id] = 'conflict'
    else emit('error', e)
  }
}

function keepMine(n: MasterNote): void {
  // Rebase the local text on the newest revision, then save it.
  status[n.id] = 'saving'
  void props.state.catchUp().then(() => save(n.id))
}

async function remove(n: MasterNote): Promise<void> {
  if (!window.confirm('Apagar esta nota?')) return
  try { await tableApi.deleteNote(n.id); props.state.local.dropNote(n.id) } catch (e) { emit('error', e) }
}
</script>

<template>
  <section class="notes">
    <form @submit.prevent="add">
      <label class="sr-only" for="quick-note">Nova nota</label>
      <textarea id="quick-note" v-model="quick" rows="2" maxlength="4000" placeholder="Anote rápido… use #tags (ex.: #pdm Tobias, pescador)" @keydown.enter.ctrl.prevent="add" />
      <button type="submit" class="small-btn">Anotar</button>
    </form>
    <div v-if="tags.length" class="tag-filter" role="group" aria-label="Filtrar por tag">
      <button type="button" class="chip" :class="{ on: !filter }" @click="filter = ''">todas</button>
      <button v-for="tg in tags" :key="tg" type="button" class="chip" :class="{ on: filter === tg }" @click="filter = tg">#{{ tg }}</button>
    </div>
    <article v-for="n in shown" :key="n.id" class="note" :class="{ pinned: n.pinned }">
      <textarea :value="bodyOf(n)" rows="3" maxlength="4000" :aria-label="`Nota ${n.tags.map(t => '#' + t).join(' ')}`" @input="edit(n, ($event.target as HTMLTextAreaElement).value)" />
      <div class="note-meta">
        <span v-for="tg in n.tags" :key="tg" class="pill">#{{ tg }}</span>
        <span class="spacer" />
        <span class="muted small" role="status">{{ status[n.id] === 'saving' ? 'Salvando…' : status[n.id] === 'saved' ? 'Salvo' : '' }}</span>
        <button type="button" class="link" :aria-pressed="n.pinned" @click="save(n.id, { pinned: !n.pinned })">{{ n.pinned ? 'Desafixar' : 'Fixar' }}</button>
        <button type="button" class="link danger" @click="remove(n)">Apagar</button>
      </div>
      <p v-if="status[n.id] === 'conflict'" class="error small" role="alert">
        Esta nota mudou em outra janela. <button type="button" class="link" @click="keepMine(n)">Manter o meu texto</button>
        <button type="button" class="link" @click="delete drafts[n.id]; delete status[n.id]">Usar a outra versão</button>
      </p>
    </article>
  </section>
</template>

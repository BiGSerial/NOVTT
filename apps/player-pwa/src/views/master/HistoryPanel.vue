<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { session as sessionApi } from '../../live/api'
import { faceCounts, outcomeText } from '../../live/dice'
import { buildHistory, type HistoryKind } from '../../live/history'
import type { SessionEvent } from '../../live/types'
import { useMaster } from './context'

/**
 * Everything that happened this session, newest first: rolls (replay / reveal), what players did
 * (requests, whispers, portraits), state changes (vitals, conditions, items) and the story (scenes,
 * clues, NPCs, weather). Reads the session's event stream from the start, then follows it live.
 */
const m = useMaster()
const s = computed(() => m.s.value)
const earlier = ref<SessionEvent[]>([])
const loading = ref(true)
const failed = ref(false)
const FILTERS: { id: 'all' | HistoryKind; name: string }[] = [
  { id: 'all', name: 'Tudo' }, { id: 'roll', name: 'Rolagens' }, { id: 'player', name: 'Jogadores' }, { id: 'state', name: 'Estado' }, { id: 'story', name: 'História' },
]
const filter = ref<'all' | HistoryKind>('all')
const q = ref('')

onMounted(async () => {
  try {
    let after = 0
    const out: SessionEvent[] = []
    for (let page = 0; page < 8; page++) { // up to 4000 events; older ones stay on the server
      const r = await sessionApi.events(s.value.session.id, after)
      out.push(...r.data)
      if (!r.has_more || !r.data.length) break
      after = r.data[r.data.length - 1]!.seq
    }
    earlier.value = out
  } catch { failed.value = true } finally { loading.value = false }
})

const names = computed(() => new Map([...s.value.characters, ...s.value.npcs].map(c => [c.id, c.name])))
const lines = computed(() => {
  const bySeq = new Map<number, SessionEvent>()
  for (const e of earlier.value) bySeq.set(e.seq, e)
  for (const e of m.t.value.events) bySeq.set(e.seq, e)
  return buildHistory([...bySeq.values()], { name: id => names.value.get(id) ?? '—', player: uid => m.playerName(uid ?? null) || 'alguém' }).reverse()
})
const shown = computed(() => {
  const needle = q.value.trim().toLowerCase()
  return lines.value.filter(l => (filter.value === 'all' || l.kind === filter.value || (filter.value === 'roll' && l.kind === 'request'))
    && (!needle || `${l.text} ${l.detail ?? ''}`.toLowerCase().includes(needle))).slice(0, 300)
})
const rollOf = (seq: number) => {
  const e = m.t.value.events.find(x => x.seq === seq) ?? earlier.value.find(x => x.seq === seq)
  const id = (e?.payload as { id?: string } | undefined)?.id
  return id ? s.value.rolls.find(r => r.id === id) ?? null : null
}
const time = (iso: string) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <section class="history" aria-label="Histórico da sessão">
    <div class="history-tools">
      <div class="seg-tabs small" role="radiogroup" aria-label="Filtrar histórico">
        <button v-for="f in FILTERS" :key="f.id" type="button" role="radio" :aria-checked="filter === f.id" :class="{ on: filter === f.id }" @click="filter = f.id">{{ f.name }}</button>
      </div>
      <input v-model="q" type="search" class="history-search" placeholder="Buscar no histórico" aria-label="Buscar no histórico" />
    </div>
    <p v-if="loading" class="muted small" role="status">Lendo o histórico…</p>
    <p v-else-if="failed" class="muted small" role="alert">Não foi possível ler o histórico anterior; mostrando o que aconteceu desde que você abriu a mesa.</p>
    <ol class="chronicle history-list">
      <li v-for="l in shown" :key="l.seq" :class="[l.kind, l.tone]">
        <time :datetime="l.at">{{ time(l.at) }}</time>
        <div>
          <p><span class="history-icon" aria-hidden="true">{{ l.icon }}</span> {{ l.text }}</p>
          <template v-if="l.kind === 'roll' && rollOf(l.seq)">
            <p class="chron-faces">
              <span v-for="(f, i) in rollOf(l.seq)!.results" :key="i" class="face" :class="{ counts: faceCounts(rollOf(l.seq)!, f) && rollOf(l.seq)!.dice.reading !== 'sum' }">{{ f }}</span>
              <b>{{ outcomeText(rollOf(l.seq)!) }}</b>
              <span v-if="rollOf(l.seq)!.secret && !rollOf(l.seq)!.revealed" class="pill secret">entre vocês</span>
            </p>
            <p class="chron-actions">
              <button type="button" class="link" @click="m.table.enqueue(rollOf(l.seq)!)">Ver de novo</button>
              <button v-if="rollOf(l.seq)!.secret && !rollOf(l.seq)!.revealed" type="button" class="link" @click="m.reveal(rollOf(l.seq)!)">Revelar à mesa</button>
            </p>
          </template>
          <p v-else-if="l.detail" class="history-detail">{{ l.detail }}</p>
        </div>
      </li>
      <li v-if="!loading && !shown.length" class="muted">{{ lines.length ? 'Nada com esse filtro.' : 'O que acontecer na sessão aparece aqui: rolagens, pedidos, mudanças de estado e a história.' }}</li>
    </ol>
  </section>
</template>

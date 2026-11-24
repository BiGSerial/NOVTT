<script setup lang="ts">
import FogVeil from '../components/live/FogVeil.vue'
import StormFlash from '../components/live/StormFlash.vue'
import { provideAtmosphere } from '../live/useAtmosphere'
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import ClockDial from '../components/live/ClockDial.vue'
import PortraitPicker from '../components/builder/PortraitPicker.vue'
import Inventory from '../components/live/Inventory.vue'
import LinkBadge from '../components/live/LinkBadge.vue'
import Portrait from '../components/live/Portrait.vue'
import RequestCard from '../components/live/RequestCard.vue'
import RollFeed from '../components/live/RollFeed.vue'
import SceneArt from '../components/live/SceneArt.vue'
import StatBoard from '../components/live/StatBoard.vue'
import Vitals from '../components/live/Vitals.vue'
import { imageUrl, rolls, table as tableApi } from '../live/api'
import { readPointer } from '../live/sheet'
import type { useLiveTable } from '../live/useLiveTable'
import { useSheets } from '../live/useSheets'
import type { Campaign, OfficialRoll, RollRequest } from '../live/types'

/**
 * The player's phone (SPEC-MVP-003): the character up front (portrait, vitals, conditions), the
 * master's request when there is one, the scene the master is showing, the sheet as a real
 * character sheet to compose a suggestion, the diary (clues as handouts, whispers) and the rolls.
 */
const props = defineProps<{ table: ReturnType<typeof useLiveTable>; campaign: Campaign }>()
const t = computed(() => props.table.state.value!)
const s = computed(() => t.value.s)
provideAtmosphere(computed(() => s.value.session.atmosphere ?? null))
const me = computed(() => s.value.me.user_id)
const sheets = useSheets(props.campaign.id)
const tab = ref<'sheet' | 'scene' | 'diary' | 'rolls'>('sheet')
const busy = ref(false)

const mine = computed(() => s.value.characters.filter(c => c.controlled_by === me.value))
const charId = ref<string | null>(null)
watch(mine, m => { if (!charId.value && m[0]) charId.value = m[0].id }, { immediate: true })
const character = computed(() => mine.value.find(c => c.id === charId.value) ?? null)
const sheet = computed(() => (charId.value ? sheets.byId.get(charId.value) : undefined))
onMounted(() => { if (character.value) void sheets.character(character.value.id, character.value.preset_version_id) })
watch(charId, id => { const c = mine.value.find(x => x.id === id); if (c) void sheets.character(c.id, c.preset_version_id) })
const text = (p: string) => { const v = sheet.value ? readPointer(sheet.value.raw, p) : undefined; return typeof v === 'string' ? v : '' }
const concept = computed(() => character.value?.concept || text('/identity/concept'))
// Identity prose from the preset's own identity section (name and concept are in the header).
const identity = computed(() => (sheet.value?.sections.find(x => x.id === 'identity')?.fields ?? [])
  .filter(f => !/\/(name|concept)$/.test(f.path) && typeof f.value === 'string' && f.value.trim()))

const myRequests = computed(() => s.value.requests.filter(r => r.roller_user_id === me.value))
const master = computed(() => s.value.members.find(x => x.role === 'master'))
const unread = ref(0)
watch(() => s.value.whispers.length + s.value.clues.filter(c => c.revealed_at).length, (n, o) => { if (n > (o ?? n) && tab.value !== 'diary') unread.value += n - (o ?? n) })
watch(tab, v => { if (v === 'diary') unread.value = 0 })
const stage = computed(() => s.value.session.stage ?? null)
const sceneNew = ref(false)
watch(() => stage.value?.shown_at, (v, o) => { if (v && v !== o && tab.value !== 'scene') sceneNew.value = true })
watch(tab, v => { if (v === 'scene') sceneNew.value = false })
// A clock shown or moved also flags the scene tab.
watch(() => JSON.stringify(s.value.session.clocks ?? []), (v, o) => { if (o !== undefined && v !== o && tab.value !== 'scene') sceneNew.value = true })

function fail(e: unknown): void {
  const code = e instanceof ApiError ? e.code : ''
  const known: Record<string, string> = {
    'roll.revision_mismatch': 'O mestre mudou o pedido; veja a versão nova.',
    'roll.request_not_open': 'Esse pedido não está mais aberto.',
    'session.paused': 'A sessão está pausada.',
    'suggestion.too_many': 'Espere o mestre responder às suas sugestões.',
    'image.type_unsupported': 'Use uma imagem JPG, PNG ou WebP.',
    'image.too_large': 'Imagem grande demais (até 4 MB e 6000 px).',
    'network.error': 'Sem conexão. Toque de novo: o servidor não rola duas vezes.',
  }
  props.table.toast(known[code] ?? 'Não deu certo. Tente de novo.')
  void t.value.catchUp()
}

async function roll(request: RollRequest, key: string, count?: number): Promise<void> {
  busy.value = true
  try {
    const result: OfficialRoll = await rolls.roll(request.id, request.revision, key, count)
    if (t.value.local.roll(result)) props.table.enqueue(result)
  } catch (e) { fail(e) } finally { busy.value = false }
}

// Suggesting a combination (D1): never an official roll by itself.
const picked = ref<string[]>([])
const note = ref('')
const toggle = (path: string) => { picked.value = picked.value.includes(path) ? picked.value.filter(p => p !== path) : [...picked.value, path].slice(-4) }
const pickedLabel = computed(() => sheet.value?.numeric.filter(f => picked.value.includes(f.path)).map(f => `${f.label} ${f.value}`).join(' + ') ?? '')
async function suggest(): Promise<void> {
  if (!character.value || !picked.value.length) return
  try {
    await rolls.suggest(s.value.session.id, character.value.id, picked.value, note.value.trim())
    props.table.toast('Sugestão enviada ao mestre')
    picked.value = []
    note.value = ''
  } catch (e) { fail(e) }
}

const reply = ref('')
async function send(): Promise<void> {
  const body = reply.value.trim()
  if (!body || !master.value) return
  try { t.value.local.whisper(await tableApi.whisper(props.campaign.id, master.value.user_id, body)); reply.value = '' } catch (e) { fail(e) }
}
const clues = computed(() => s.value.clues.filter(c => c.revealed_at).slice().reverse())
const turn = computed(() => s.value.session.initiative?.entries?.[s.value.session.initiative.turn ?? 0])

</script>

<template>
  <div class="player" :class="{ paused: s.session.status === 'paused' }">
    <FogVeil />
    <StormFlash />
    <header class="player-hero">
      <SceneArt :art="stage?.art ?? 'road'" :image-id="stage?.image_id ?? null" />
      <div class="hero-shade" />
      <div class="hero-top">
        <a href="#/campanhas" class="brand-link" aria-label="Campanhas"><BrandMark /></a>
        <span class="hero-session">{{ s.session.title }}</span>
        <span class="spacer" />
        <LinkBadge :state="table.link.value" />
      </div>
      <div v-if="character" class="hero-char">
        <Portrait :name="character.name" :image-id="character.portrait_image_id" :live="character.live" :size="92" />
        <div class="hero-id">
          <h1 class="char-name">{{ character.name }}</h1>
          <p v-if="concept" class="char-concept">{{ concept }}</p>
          <Vitals :live="character.live" />
        </div>
      </div>
      <p v-else class="hero-empty">Você não tem personagem nesta campanha.</p>
    </header>

    <p v-if="s.session.status === 'paused'" class="banner">Sessão pausada pelo mestre.</p>
    <p v-if="turn" class="turn-banner" :class="{ mine: turn.id === charId }">{{ turn.id === charId ? 'É a sua vez!' : `Vez de ${turn.name}` }}</p>

    <section v-if="myRequests.length" class="requests" aria-label="Pedidos do mestre">
      <RequestCard v-for="r in myRequests" :key="r.id" :request="r" :can-roll="s.session.status === 'active'" :busy="busy" @roll="roll" />
    </section>

    <main class="player-main">
      <section v-if="tab === 'sheet'" aria-label="Ficha" class="player-sheet">
        <select v-if="mine.length > 1" v-model="charId" aria-label="Personagem"><option v-for="c in mine" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <article v-if="sheet && character" class="paper-sheet" aria-label="Ficha do personagem">
          <header class="paper-head">
            <PortraitPicker
              class="paper-portrait" :campaign-id="campaign.id" :character-id="character.id" :name="character.name"
              :image-id="character.portrait_image_id" :size="108" editable @change="id => t.local.portrait('character', character!.id, id)"
            />
            <dl class="paper-id">
              <div class="paper-field wide"><dt>Personagem</dt><dd class="paper-name">{{ character.name }}</dd></div>
              <div v-if="concept" class="paper-field wide"><dt>Conceito</dt><dd>{{ concept }}</dd></div>
              <div class="paper-field"><dt>Jogador</dt><dd>{{ s.me.name }}</dd></div>
              <div class="paper-field"><dt>Campanha</dt><dd>{{ campaign.name }}</dd></div>
            </dl>
          </header>
          <section class="paper-conditions" aria-label="Condições">
            <span class="paper-label">Condições</span>
            <span v-for="c in character.live?.conditions ?? []" :key="c" class="paper-stamp">{{ c }}</span>
            <span v-if="!character.live?.conditions?.length" class="paper-none">nenhuma</span>
          </section>
          <p class="paper-hint">Toque em um atributo e uma perícia para sugerir uma rolagem ao mestre.</p>
          <StatBoard class="paper-stats" :sections="sheet.sections" :selected="picked" selectable :hide="['identity', 'gear']" @toggle="toggle" />
          <section class="paper-box">
            <h3 class="paper-title">Mochila</h3>
            <Inventory :items="character.live?.items ?? []" />
            <p v-if="text('/gear/items')" class="paper-note">{{ text('/gear/items') }}</p>
          </section>
          <section v-if="identity.length" class="paper-box">
            <h3 class="paper-title">Quem é</h3>
            <dl class="paper-lines">
              <div v-for="f in identity" :key="f.path"><dt>{{ f.label }}</dt><dd>{{ f.value }}</dd></div>
            </dl>
          </section>
        </article>
        <p v-else-if="character" role="status">Abrindo a ficha…</p>
        <div v-if="picked.length" class="suggest-bar">
          <p><strong>{{ pickedLabel }}</strong></p>
          <input v-model="note" maxlength="200" placeholder="O que você quer fazer? (opcional)" aria-label="Intenção" />
          <div class="row"><button type="button" class="primary" @click="suggest">Sugerir ao mestre</button><button type="button" class="link" @click="picked = []">Limpar</button></div>
          <p class="muted small">O mestre decide os dados. Para treinar, use os <a href="#/dados" target="_blank" rel="noopener">dados livres</a> (não oficiais).</p>
        </div>
      </section>

      <section v-else-if="tab === 'scene'" aria-label="Cena" class="player-scene">
        <article v-if="stage" class="stage-card">
          <SceneArt :art="stage.art" :image-id="stage.image_id" />
          <div class="stage-text">
            <p class="eyebrow">Agora</p>
            <h2>{{ stage.title }}</h2>
            <p v-if="stage.text" class="read">{{ stage.text }}</p>
          </div>
        </article>
        <p v-else class="muted panel">Quando o mestre mostrar uma cena, ela aparece aqui.</p>
        <section v-if="s.session.clocks?.length" class="player-clocks" aria-label="Relógios">
          <h3 class="sheet-heading">Relógios</h3>
          <ClockDial v-for="c in s.session.clocks" :key="c.id" :label="c.label" :segments="c.segments" :filled="c.filled" :tone="c.tone" :size="64" />
        </section>
        <template v-if="s.session.revealed_npcs.length">
          <h2 class="sheet-heading">Quem vocês conheceram</h2>
          <article v-for="n in s.session.revealed_npcs" :key="n.id" class="met">
            <Portrait :name="n.name" :image-id="n.portrait_image_id" :size="56" :ring="false" kind="npc" />
            <div><strong>{{ n.name }}</strong><p v-if="n.summary" class="small">{{ n.summary }}</p></div>
          </article>
        </template>
        <h2 class="sheet-heading">O grupo</h2>
        <div class="party-grid">
          <article v-for="c in s.characters" :key="c.id" class="party-member" :class="{ me: c.id === charId }">
            <Portrait :name="c.name" :image-id="c.portrait_image_id" :live="c.live" :size="52" />
            <div><strong>{{ c.name }}</strong><small v-if="c.live?.conditions?.length">{{ c.live.conditions.join(' · ') }}</small></div>
          </article>
        </div>
        <template v-if="s.session.initiative?.entries?.length">
          <h2 class="sheet-heading">Iniciativa</h2>
          <ol class="initiative">
            <li v-for="(e, i) in s.session.initiative.entries" :key="e.id" :class="{ turn: i === (s.session.initiative.turn ?? 0), me: e.id === charId }">{{ e.name }}</li>
          </ol>
        </template>
      </section>

      <section v-else-if="tab === 'diary'" aria-label="Diário" class="diary">
        <h2 class="sheet-heading">Pistas e achados</h2>
        <article v-for="c in clues" :key="c.id" class="handout">
          <img v-if="c.image_id" :src="imageUrl(c.image_id)!" alt="" loading="lazy" />
          <p class="handout-who">{{ c.revealed_to === 'table' ? 'Para todos' : 'Só para você' }}</p>
          <h3>{{ c.title }}</h3>
          <p>{{ c.body }}</p>
        </article>
        <p v-if="!clues.length" class="muted small">Nenhuma pista ainda.</p>
        <h2 class="sheet-heading">Sussurros com o mestre</h2>
        <ol class="thread">
          <li v-for="w in s.whispers" :key="w.id" :class="{ out: w.sender_user_id === me }"><span v-if="w.kind === 'clue'" class="pill">pista</span>{{ w.body }}</li>
          <li v-if="!s.whispers.length" class="muted small">O que o mestre sussurrar só para você aparece aqui.</li>
        </ol>
        <form class="row" @submit.prevent="send"><input v-model="reply" maxlength="2000" placeholder="Responder só ao mestre" aria-label="Mensagem ao mestre" /><button type="submit" class="small-btn">Enviar</button></form>
      </section>

      <section v-else aria-label="Rolagens"><RollFeed :rolls="s.rolls" :me-id="me" @replay="table.enqueue" /></section>
    </main>

    <nav class="bottom-nav" aria-label="Seções">
      <button type="button" :class="{ on: tab === 'sheet' }" :aria-current="tab === 'sheet' ? 'page' : undefined" @click="tab = 'sheet'"><span aria-hidden="true">✦</span>Ficha</button>
      <button type="button" :class="{ on: tab === 'scene' }" :aria-current="tab === 'scene' ? 'page' : undefined" @click="tab = 'scene'"><span aria-hidden="true">☾</span>Cena<span v-if="sceneNew" class="badge">!</span></button>
      <button type="button" :class="{ on: tab === 'diary' }" :aria-current="tab === 'diary' ? 'page' : undefined" @click="tab = 'diary'"><span aria-hidden="true">✎</span>Diário<span v-if="unread" class="badge">{{ unread }}</span></button>
      <button type="button" :class="{ on: tab === 'rolls' }" :aria-current="tab === 'rolls' ? 'page' : undefined" @click="tab = 'rolls'"><span aria-hidden="true">⬡</span>Rolagens</button>
    </nav>
  </div>
</template>

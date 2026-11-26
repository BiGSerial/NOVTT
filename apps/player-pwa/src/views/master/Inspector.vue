<script setup lang="ts">
import { computed, ref } from 'vue'
import Inventory from '../../components/live/Inventory.vue'
import Portrait from '../../components/live/Portrait.vue'
import StatBoard from '../../components/live/StatBoard.vue'
import Vitals from '../../components/live/Vitals.vue'
import { describeDice } from '../../live/dice'
import RollTray from './RollTray.vue'
import { useMaster, type CastEntry } from './context'

/**
 * One character or NPC, open on the table: portrait, vitals, conditions and items under the
 * master's hand; the sheet as a board where tapping an attribute and a skill composes the roll.
 */
const props = defineProps<{ entry: CastEntry }>()
const m = useMaster()
const sheet = computed(() => m.sheets.byId.get(props.entry.id))
const requests = computed(() => m.pendingFor(props.entry.id))
// What the player asked for: a banner on the sheet, and the asked fields lit on the board.
const asks = computed(() => m.s.value.suggestions.filter(sg => sg.campaign_character_id === props.entry.id))
const asked = computed(() => [...new Set(asks.value.flatMap(sg => sg.paths))])
const file = ref<HTMLInputElement | null>(null)
const whisperText = ref('')
const revealing = ref(false)
const revealText = ref('')
const isNpc = computed(() => props.entry.kind === 'npc')
const revealed = computed(() => m.s.value.session.revealed_npcs.some(n => n.id === props.entry.id))
const concept = computed(() => props.entry.concept ?? (sheet.value?.raw && typeof (sheet.value.raw as { identity?: { concept?: string } }).identity?.concept === 'string' ? (sheet.value.raw as { identity: { concept: string } }).identity.concept : ''))

async function pick(ev: Event): Promise<void> {
  const f = (ev.target as HTMLInputElement).files?.[0]
  if (f) await m.setPortrait(props.entry.kind, props.entry.id, f)
  if (file.value) file.value.value = ''
}
async function whisper(): Promise<void> {
  if (props.entry.playerUserId && await m.whisper(props.entry.playerUserId, whisperText.value)) {
    whisperText.value = ''
    m.table.toast(`Sussurro enviado a ${props.entry.sub}`)
  }
}
async function reveal(): Promise<void> {
  await m.revealNpc(props.entry.id, revealText.value.trim())
  revealing.value = false
  revealText.value = ''
}
</script>

<template>
  <article class="inspector" :aria-label="`Ficha de ${entry.name}`">
    <header class="inspector-head">
      <button type="button" class="portrait-btn" :title="'Trocar retrato'" @click="file?.click()">
        <Portrait :name="entry.name" :image-id="entry.portrait" :live="entry.live" :size="112" :kind="entry.kind" />
        <span class="portrait-edit">Trocar retrato</span>
      </button>
      <input ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :aria-label="`Retrato de ${entry.name}`" @change="pick" />
      <div class="inspector-id">
        <p class="eyebrow">{{ isNpc ? (m.inScene.value.includes(entry.id) ? 'PdM em cena' : 'PdM na reserva') : `Jogador: ${entry.sub}` }}</p>
        <h2 class="char-name">{{ entry.name }}</h2>
        <p v-if="concept" class="char-concept">{{ concept }}</p>
        <div class="inspector-actions">
          <template v-if="!isNpc">
            <button type="button" class="small-btn" @click="m.spotlight(entry.id)">★ Holofote ({{ m.compass.spotlight[entry.id] ?? 0 }})</button>
            <form class="inline-whisper" @submit.prevent="whisper">
              <input v-model="whisperText" maxlength="2000" :placeholder="`Sussurrar a ${entry.sub}…`" :aria-label="`Sussurro para ${entry.sub}`" />
              <button type="submit" class="small-btn" :disabled="!whisperText.trim()">Enviar</button>
            </form>
          </template>
          <template v-else>
            <button type="button" class="small-btn" @click="m.toggleInScene(entry.id)">{{ m.inScene.value.includes(entry.id) ? 'Tirar da cena' : 'Trazer à cena' }}</button>
            <button type="button" class="small-btn" @click="revealing = true">{{ revealed ? 'Atualizar para a mesa' : 'Apresentar à mesa' }}</button>
          </template>
        </div>
      </div>
    </header>

    <section v-for="sg in asks" :key="sg.id" class="ask-banner" :class="{ active: m.suggestionId.value === sg.id }" aria-live="polite">
      <span class="ask-hand" aria-hidden="true">✋</span>
      <p>
        <strong>{{ entry.sub || 'O jogador' }} pede</strong> {{ m.pathLabels(entry.id, sg.paths) }}
        <span v-if="sg.note" class="ask-note">“{{ sg.note }}”</span>
      </p>
      <div class="ask-actions">
        <button v-if="m.suggestionId.value !== sg.id" type="button" class="small-btn" @click="m.fromSuggestion(sg)">Preparar rolagem</button>
        <span v-else class="muted small">Ajuste a rolagem abaixo e peça.</span>
        <button type="button" class="link danger" @click="m.dismiss(sg)">Dispensar</button>
      </div>
    </section>

    <div v-if="requests.length" class="pending-strip">
      <div v-for="r in requests" :key="r.id" class="pending-row">
        <span class="pending-dot" aria-hidden="true" />
        <span><strong>{{ r.label }}</strong> · {{ describeDice(r.dice, r.allows_choice ? `${r.min_dice}–${r.max_dice}` : r.max_dice) }} · aguardando {{ entry.sub || 'o jogador' }} <span v-if="r.secret" class="pill secret">entre vocês</span></span>
        <button type="button" class="link" @click="m.grant(r)">+1 tentativa</button>
        <button type="button" class="link danger" @click="m.cancel(r)">Cancelar</button>
      </div>
    </div>

    <div class="inspector-grid">
      <section class="inspector-side">
        <h3 class="sheet-heading">Estado</h3>
        <Vitals :live="entry.live" :name="entry.name" editable big :busy="m.busy.value" @resources="r => m.saveLive(entry.kind, entry.id, { resources: r })" @conditions="c => m.saveLive(entry.kind, entry.id, { conditions: c })" />
        <h3 class="sheet-heading">Mochila</h3>
        <Inventory :items="entry.live?.items ?? []" editable :busy="m.busy.value" @save="items => m.saveLive(entry.kind, entry.id, { items })" />
      </section>
      <section class="inspector-sheet">
        <StatBoard v-if="sheet" :sections="sheet.sections" :selected="m.paths.value" :asked="asked" selectable :hide="['identity']" @toggle="m.togglePath" />
        <p v-else class="muted" role="status">Abrindo a ficha…</p>
      </section>
    </div>

    <RollTray :targets="[{ kind: entry.kind, id: entry.id }]" :fields="sheet?.numeric ?? []" />

    <div v-if="revealing" class="drawer-veil" @click.self="revealing = false">
      <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="reveal-title" @submit.prevent="reveal" @keydown.escape="revealing = false">
        <h2 id="reveal-title">Apresentar {{ entry.name }} à mesa</h2>
        <div class="reveal-preview"><Portrait :name="entry.name" :image-id="entry.portrait" :size="72" :ring="false" kind="npc" /><p class="muted small">Os jogadores veem o nome, o retrato e o que você escrever. Nunca números nem segredos.</p></div>
        <label>O que eles percebem <textarea v-model="revealText" rows="3" maxlength="300" placeholder="Ex.: Um velho de capa encharcada, que conta moedas enquanto fala." /></label>
        <div class="row"><button type="submit" class="primary">Apresentar</button><button type="button" class="link" @click="revealing = false">Cancelar</button></div>
      </form>
    </div>
  </article>
</template>

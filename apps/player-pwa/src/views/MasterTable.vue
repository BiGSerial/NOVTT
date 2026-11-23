<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import BrandMark from '../components/BrandMark.vue'
import LinkBadge from '../components/live/LinkBadge.vue'
import { devices, session as sessionApi } from '../live/api'
import { loadTheme, saveTheme, THEMES, type ThemeId } from '../live/theme'
import type { useLiveTable } from '../live/useLiveTable'
import type { Campaign } from '../live/types'
import CastRail from './master/CastRail.vue'
import InitiativeStrip from './master/InitiativeStrip.vue'
import Inspector from './master/Inspector.vue'
import PartyBoard from './master/PartyBoard.vue'
import SceneBanner from './master/SceneBanner.vue'
import StoryPanel from './master/StoryPanel.vue'
import { createMasterContext, provideMaster } from './master/context'

/**
 * The master's console (SPEC-MVP-003): the scene on top (walk the plot, clocks, show it to the
 * players), the cast on the left, the open character or group in the middle with the roll tray,
 * and the story on the right (clues of this scene, chronicle, whispers, notes, oracle).
 */
const props = defineProps<{ table: ReturnType<typeof useLiveTable>; campaign: Campaign }>()
const m = createMasterContext(props.table, props.campaign)
provideMaster(m)
const s = m.s
const theme = ref<ThemeId>(loadTheme(props.campaign.id))
watch(theme, v => saveTheme(props.campaign.id, v))

onMounted(() => {
  const first = s.value.characters[0]
  if (first) m.select('character', first.id)
  if (!m.inScene.value.length) {
    const sc = m.currentScene.value
    if (sc?.npcs?.length) {
      const names = sc.npcs.map(n => n.toLowerCase())
      m.inScene.value = s.value.npcs.filter(n => names.some(x => n.name.toLowerCase().includes(x) || x.includes(n.name.toLowerCase()))).map(n => n.id)
    }
  }
})
const many = computed(() => m.selection.value.length > 1)
function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape' && !(e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) && many.value) m.clearSelection()
}

async function transition(action: 'pause' | 'resume' | 'end'): Promise<void> {
  if (action === 'end' && !window.confirm('Encerrar a sessão? Pedidos abertos serão fechados; o histórico fica salvo.')) return
  try {
    m.t.value.local.session(await sessionApi.transition(s.value.session.id, action))
    if (action === 'end') await props.table.reload()
  } catch (e) { m.fail(e) }
}

// ---------- Pairing ----------
const pairing = ref(false)
const code = ref('')
const paired = ref<{ id: string; label: string; last_seen_at: string | null }[]>([])
async function refreshDevices(): Promise<void> { try { paired.value = (await devices.list()).data } catch { paired.value = [] } }
async function openPairing(): Promise<void> { pairing.value = true; await refreshDevices() }
async function confirmCode(): Promise<void> {
  try {
    const r = await devices.confirm(code.value.trim())
    props.table.toast(`${r.device_label} pareado. Ele abre sozinho em instantes.`)
    code.value = ''
    setTimeout(() => void refreshDevices(), 2500)
  } catch (e) { m.fail(e, 'Código inválido ou expirado. Peça um novo no tablet.') }
}
async function revoke(id: string): Promise<void> { try { await devices.revoke(id); paired.value = paired.value.filter(d => d.id !== id) } catch (e) { m.fail(e) } }
const origin = location.origin
</script>

<template>
  <div class="master-table" :class="{ paused: s.session.status === 'paused' }" @keydown="onKey">
    <header class="topbar master-bar">
      <a href="#/campanhas" class="brand-link" aria-label="Voltar às campanhas"><BrandMark /></a>
      <div class="session-name">
        <p class="eyebrow">{{ campaign.name }}</p>
        <strong>{{ s.session.title }}</strong>
      </div>
      <span v-if="s.session.status === 'paused'" class="pill secret">pausada</span>
      <span class="spacer" />
      <LinkBadge :state="table.link.value" />
      <nav class="tools" aria-label="Ferramentas">
        <a class="tool" :href="`#/roteiro/${campaign.id}`" target="_blank" rel="noopener">Roteiro</a>
        <a class="tool" :href="`#/atlas/${campaign.id}`" target="_blank" rel="noopener">Atlas</a>
        <button type="button" class="tool" @click="openPairing">Tablet</button>
        <label class="tool theme-pick"><span class="sr-only">Tema</span><select v-model="theme" aria-label="Tema"><option v-for="th in THEMES" :key="th.id" :value="th.id">{{ th.name }}</option></select></label>
        <button v-if="s.session.status === 'active'" type="button" class="tool" @click="transition('pause')">Pausar</button>
        <button v-else type="button" class="tool" @click="transition('resume')">Retomar</button>
        <button type="button" class="tool danger" @click="transition('end')">Encerrar</button>
      </nav>
    </header>

    <SceneBanner />
    <InitiativeStrip />

    <div class="console">
      <CastRail />
      <main class="workbench" aria-label="Mesa">
        <PartyBoard v-if="many" />
        <Inspector v-else-if="m.focus.value" :key="m.focus.value.id" :entry="m.focus.value" />
        <div v-else class="empty-bench">
          <p class="char-name">Escolha alguém do elenco</p>
          <p class="muted">Clique em um retrato para abrir a ficha. Segure <kbd>Ctrl</kbd> para juntar vários e agir no grupo todo.</p>
          <div class="row"><button type="button" class="primary" @click="m.selectMany(s.characters.map(c => ({ kind: 'character', id: c.id })))">Selecionar o grupo</button></div>
        </div>
      </main>
      <StoryPanel />
    </div>

    <div v-if="pairing" class="drawer-veil" @click.self="pairing = false">
      <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="pair-title" @keydown.escape="pairing = false">
        <h2 id="pair-title">Parear um tablet</h2>
        <ol class="small"><li>No tablet, abra <strong>{{ origin }}/#/tablet</strong>.</li><li>Digite aqui o código de 6 dígitos que aparece nele.</li></ol>
        <form class="row" @submit.prevent="confirmCode"><input v-model="code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" aria-label="Código do tablet" /><button type="submit" class="primary">Confirmar</button></form>
        <h3>Aparelhos pareados</h3>
        <ul class="devices"><li v-for="d in paired" :key="d.id">{{ d.label }} <button type="button" class="link danger" @click="revoke(d.id)">Desparear</button></li><li v-if="!paired.length" class="muted small">Nenhum.</li></ul>
        <button type="button" class="ghost" @click="pairing = false">Fechar</button>
      </section>
    </div>
  </div>
</template>

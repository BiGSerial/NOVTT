<script setup lang="ts">
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue'
import { loadMe, meLoaded } from './live/user'

// Hash routing (no server routes needed; the same build serves phone, tablet and desktop).
const CharacterDraftView = defineAsyncComponent(() => import('./views/CharacterDraftView.vue'))
const FreeDiceView = defineAsyncComponent(() => import('./views/FreeDiceView.vue'))
const LandingView = defineAsyncComponent(() => import('./views/LandingView.vue'))
const CampaignsView = defineAsyncComponent(() => import('./views/CampaignsView.vue'))
const TableView = defineAsyncComponent(() => import('./views/TableView.vue'))
const TabletView = defineAsyncComponent(() => import('./views/TabletView.vue'))
const RoteiroView = defineAsyncComponent(() => import('./views/RoteiroView.vue'))
const AtlasView = defineAsyncComponent(() => import('./views/AtlasView.vue'))
const CampaignCharactersView = defineAsyncComponent(() => import('./views/campaign/CampaignCharactersView.vue'))

const route = ref(location.hash)
const onHash = (): void => { route.value = location.hash }
window.addEventListener('hashchange', onHash)
onBeforeUnmount(() => window.removeEventListener('hashchange', onHash))
onMounted(() => { void loadMe().catch(() => undefined) })

const UUID = '([0-9a-f-]{36})'
const match = (pattern: string) => new RegExp(`^#/${pattern}$`, 'i').exec(route.value)?.[1] ?? null
const view = computed(() => {
  let id: string | null
  if ((id = match(`personagens/${UUID}`))) return { c: CharacterDraftView, props: { characterId: id } }
  if ((id = match(`campanha/${UUID}/personagens`))) return { c: CampaignCharactersView, props: { campaignId: id } }
  if ((id = match(`mesa/${UUID}`))) return { c: TableView, props: { campaignId: id } }
  if ((id = match(`roteiro/${UUID}`))) return { c: RoteiroView, props: { campaignId: id } }
  if ((id = match(`atlas/${UUID}`))) return { c: AtlasView, props: { campaignId: id } }
  if ((id = match(`tablet/${UUID}`))) return { c: TabletView, props: { campaignId: id } }
  if (/^#\/tablet\/?$/.test(route.value)) return { c: TabletView, props: {} }
  if (/^#\/dados\/?$/.test(route.value)) return { c: FreeDiceView, props: {} }
  if (/^#\/campanhas\/?$/.test(route.value)) return { c: CampaignsView, props: {} }
  return { c: LandingView, props: {} }
})
</script>

<template>
  <component :is="view.c" v-if="meLoaded" :key="route" v-bind="view.props" />
  <p v-else class="boot" role="status">Abrindo a mesa…</p>
</template>

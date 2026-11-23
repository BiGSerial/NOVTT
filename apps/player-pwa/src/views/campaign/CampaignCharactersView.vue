<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue'
import BrandMark from '../../components/BrandMark.vue'
import { labelsOf, presets, setup, type CampaignCharacterView, type PresetVersionFull } from '../../live/campaign'
import { go, me } from '../../live/user'
import CharacterReview from './CharacterReview.vue'
import PresetBadge from './PresetBadge.vue'
import { api } from '../../api/client'
import type { Campaign } from '../../live/types'

/** Master: every character of a campaign, its creation progress, proposals, grants and activation. */
const props = defineProps<{ campaignId: string }>()
const campaign = ref<Campaign | null>(null)
const preset = shallowRef<PresetVersionFull | null>(null)
const characters = ref<CampaignCharacterView[]>([])
const error = ref('')
const loading = ref(true)
const labels = computed(() => (preset.value ? labelsOf(preset.value.sheet_ui) : new Map<string, string>()))

async function load(): Promise<void> {
  try {
    const c = await api<Campaign>('GET', `/campaigns/${props.campaignId}`)
    campaign.value = c
    const [p, list] = await Promise.all([presets.version(c.preset_version_id), setup.characters(props.campaignId)])
    preset.value = p
    characters.value = list.data
  } catch { error.value = 'Não foi possível carregar os personagens.' } finally { loading.value = false }
}

onMounted(() => {
  if (!me.value) return go('#/')
  void load()
})
</script>

<template>
  <main class="page wide campaign-characters">
    <header class="topbar">
      <a href="#/" class="brand-link"><BrandMark /></a>
      <a href="#/campanhas" class="ghost button">← Campanhas</a>
      <span class="spacer" />
      <a v-if="campaign" class="button" :href="`#/mesa/${campaign.id}`">Abrir a mesa</a>
    </header>
    <p v-if="loading" role="status">Carregando…</p>
    <p v-else-if="error" class="error" role="alert">{{ error }}</p>
    <template v-else-if="campaign && preset">
      <div class="campaigns-head">
        <div>
          <p class="eyebrow">Personagens e propostas</p>
          <h1>{{ campaign.name }}</h1>
        </div>
      </div>
      <section class="preset-summary" aria-label="Preset da campanha"><PresetBadge :preset="preset" /></section>
      <p v-if="!characters.length" class="muted">Nenhum personagem ainda. Convide jogadores na lista de campanhas; eles criam a ficha e enviam para você.</p>
      <div class="review-grid">
        <CharacterReview v-for="c in characters" :key="`${c.id}-${c.sheet_revision}-${c.status}`" :character="c" :preset="preset" :labels="labels" @changed="load" />
      </div>
    </template>
  </main>
</template>

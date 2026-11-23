<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError } from '../../api/client'
import { t } from '../../i18n/messages'
import { presets, setup, STATUS_LABEL, type CampaignCharacterView, type PresetVersionFull } from '../../live/campaign'
import type { Campaign } from '../../live/types'
import { go } from '../../live/user'
import PresetBadge from './PresetBadge.vue'

/** One campaign: its preset, the viewer's characters (player) or the master's tools. */
const props = defineProps<{ campaign: Campaign }>()
const preset = ref<PresetVersionFull | null>(null)
const characters = ref<CampaignCharacterView[]>([])
const creating = ref(false)
const newName = ref('')
const busy = ref(false)
const error = ref('')
const token = ref('')
const copied = ref(false)
let createKey: string | null = null
const isMaster = props.campaign.role === 'master'

onMounted(async () => {
  try {
    const [p, c] = await Promise.all([presets.version(props.campaign.preset_version_id), setup.characters(props.campaign.id)])
    preset.value = p
    characters.value = c.data
  } catch { error.value = 'Não foi possível carregar os detalhes desta campanha.' }
})

async function createCharacter(): Promise<void> {
  if (!newName.value.trim()) { error.value = 'Dê um nome ao personagem.'; return }
  busy.value = true
  error.value = ''
  createKey ??= crypto.randomUUID()
  try {
    const character = await setup.createCharacter(props.campaign.id, newName.value.trim(), createKey)
    createKey = null
    go(`#/personagens/${character.id}`)
  } catch (e) {
    error.value = t(e instanceof ApiError ? e.code : 'network.error')
    if (e instanceof ApiError && e.status !== 0) createKey = null
  } finally { busy.value = false }
}

async function invite(): Promise<void> {
  busy.value = true
  error.value = ''
  copied.value = false
  try { token.value = (await setup.invite(props.campaign.id)).token ?? '' } catch (e) { error.value = t(e instanceof ApiError ? e.code : 'network.error') } finally { busy.value = false }
}

async function copy(): Promise<void> {
  try { await navigator.clipboard.writeText(token.value); copied.value = true } catch { copied.value = false }
}
</script>

<template>
  <li class="campaign-card preset-campaign">
    <div class="campaign-main">
      <p class="eyebrow">{{ isMaster ? 'Você mestra' : 'Você joga' }}</p>
      <h2>{{ campaign.name }}</h2>
      <p v-if="campaign.description" class="muted small">{{ campaign.description }}</p>
      <PresetBadge v-if="preset" :preset="preset" compact />
    </div>

    <div v-if="!isMaster" class="my-characters">
      <ul v-if="characters.length" class="character-chips" aria-label="Seus personagens">
        <li v-for="c in characters" :key="c.id">
          <a class="character-chip" :href="`#/personagens/${c.id}`">
            <span class="chip-name">{{ c.name }}</span>
            <span class="status-pill" :class="c.status">{{ STATUS_LABEL[c.status] }}</span>
          </a>
        </li>
      </ul>
      <form v-if="creating" class="inline-create" @submit.prevent="createCharacter">
        <label class="field">Nome do personagem
          <input v-model="newName" type="text" maxlength="120" autocomplete="off" />
        </label>
        <button type="submit" class="primary" :disabled="busy">Começar a ficha</button>
        <button type="button" class="ghost" @click="creating = false">Cancelar</button>
      </form>
      <button v-else-if="!characters.length" type="button" class="primary" @click="creating = true">Criar personagem</button>
    </div>

    <div class="card-actions">
      <a class="button primary" :href="`#/mesa/${campaign.id}`">Abrir a mesa</a>
      <template v-if="isMaster">
        <a class="button" :href="`#/campanha/${campaign.id}/personagens`">Personagens e propostas</a>
        <a class="button" :href="`#/roteiro/${campaign.id}`">Roteiro</a>
        <a class="button" :href="`#/atlas/${campaign.id}`">Atlas</a>
        <a class="button" :href="`#/tablet/${campaign.id}`">Fichário (tablet)</a>
        <button type="button" class="ghost" :disabled="busy" @click="invite">Convidar jogadores</button>
      </template>
      <a v-else class="button" :href="`#/atlas/${campaign.id}`">O que sabemos</a>
    </div>
    <div v-if="token" class="invite-box" role="region" aria-label="Convite">
      <p class="small">Envie este código ao jogador (vale 72 h, até 10 usos). Ele entra em "Entrar com convite".</p>
      <code class="invite-token">{{ token }}</code>
      <button type="button" class="ghost" @click="copy">{{ copied ? 'Copiado' : 'Copiar código' }}</button>
    </div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
  </li>
</template>

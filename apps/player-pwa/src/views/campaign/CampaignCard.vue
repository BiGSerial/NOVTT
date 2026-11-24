<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '../../api/client'
import { t } from '../../i18n/messages'
import { presets, setup, STATUS_LABEL, type CampaignCharacterView, type PresetVersionFull } from '../../live/campaign'
import type { Campaign } from '../../live/types'
import { go } from '../../live/user'
import Portrait from '../../components/live/Portrait.vue'
import ActionDialog from '../../components/ui/ActionDialog.vue'
import PresetBadge from './PresetBadge.vue'

/** One campaign: its preset, the viewer's characters (player) or the master's tools. */
const props = defineProps<{ campaign: Campaign }>()
const emit = defineEmits<{ changed: [] }>()
const preset = ref<PresetVersionFull | null>(null)
const characters = ref<CampaignCharacterView[]>([])
const creating = ref(false)
const loaded = ref(false)
const newName = ref('')
const busy = ref(false)
const error = ref('')
const token = ref('')
const copied = ref(false)
let createKey: string | null = null
const isMaster = props.campaign.role === 'master'
const live = computed(() => props.campaign.last_session && props.campaign.last_session.status !== 'ended' ? props.campaign.last_session : null)
const masters = computed(() => (props.campaign.masters ?? []).map(m => m.name).join(', '))
/** "hoje", "ontem", "há 3 dias", "12 de nov." — a short relative date. */
function when(iso: string): string {
  const d = new Date(iso)
  const days = Math.floor((Date.now() - d.getTime()) / 86_400_000)
  if (days <= 0) return 'hoje'
  if (days === 1) return 'ontem'
  if (days < 14) return `há ${days} dias`
  return d.toLocaleDateString('pt-BR', { day: 'numeric', month: 'short', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' })
}
// Characters built on another version of the campaign's sheet need adapting.
const versions = ref<Record<string, string>>({})
const outdated = (c: CampaignCharacterView) => c.preset_version_id !== props.campaign.preset_version_id
// Master: newer versions of this campaign's preset.
const upgrades = ref<{ id: string; version: string }[]>([])
const upgrading = ref(false)
const upgradeTo = ref('')
async function upgrade(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    await setup.upgradePreset(props.campaign.id, upgradeTo.value, props.campaign.revision ?? 1)
    upgrading.value = false
    emit('changed')
  } catch (e) { error.value = t(e instanceof ApiError ? e.code : 'network.error') } finally { busy.value = false }
}

onMounted(async () => {
  try {
    const [p, c] = await Promise.all([presets.version(props.campaign.preset_version_id), setup.characters(props.campaign.id)])
    preset.value = p
    characters.value = c.data
    loaded.value = true
    if (isMaster) setup.presetUpgrades(props.campaign.id).then(u => { upgrades.value = u.data }).catch(() => undefined)
    for (const ch of c.data.filter(outdated)) {
      presets.version(ch.preset_version_id).then(v => { versions.value = { ...versions.value, [ch.id]: v.version } }).catch(() => undefined)
    }
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
  <li class="campaign-card camp" :class="{ 'is-live': live?.status === 'active' }">
    <header class="camp-head">
      <div class="camp-title">
        <p class="camp-role"><span class="tag" :class="isMaster ? 'role-master' : 'role-player'">{{ isMaster ? 'Você mestra' : 'Você joga' }}</span></p>
        <h2>{{ campaign.name }}</h2>
      </div>
      <a v-if="live" class="camp-live" :href="`#/mesa/${campaign.id}`">
        <span class="live-dot" aria-hidden="true" />{{ live.status === 'active' ? 'Sessão ao vivo' : 'Sessão pausada' }}<small>{{ live.title }}</small>
      </a>
    </header>

    <dl class="camp-facts">
      <div><dt>Mestre</dt><dd>{{ isMaster && masters ? `${masters} (você)` : masters || '—' }}</dd></div>
      <div><dt>Jogadores</dt><dd>{{ campaign.players_count ?? 0 }}</dd></div>
      <div><dt>Última sessão</dt><dd>
        <template v-if="campaign.last_session">{{ when(campaign.last_session.started_at) }} <small>· {{ campaign.last_session.title }}</small></template>
        <template v-else>ainda não jogada</template>
      </dd></div>
    </dl>
    <p v-if="campaign.description" class="camp-desc">{{ campaign.description }}</p>

    <section class="camp-sheet" aria-label="Sistema e ficha da campanha">
      <PresetBadge v-if="preset" :preset="preset" compact />
      <p v-else-if="!error" class="muted small" role="status">Carregando o sistema…</p>
    </section>

    <section v-if="!isMaster && loaded" class="my-characters" aria-label="Seus personagens">
      <ul v-if="characters.length" class="character-chips">
        <li v-for="c in characters" :key="c.id">
          <a class="character-chip" :href="`#/personagens/${c.id}`">
            <Portrait :name="c.name" :image-id="c.portrait_image_id ?? null" :size="40" :ring="false" />
            <span class="chip-text">
              <span class="chip-name">{{ c.name }}</span>
              <small v-if="outdated(c)" class="chip-warn">⚠ Ficha v{{ versions[c.id] ?? '…' }}; a campanha usa v{{ preset?.version ?? '…' }}. <a href="#/personagens" @click.stop>Adaptar</a></small>
            </span>
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
    </section>

    <footer class="card-actions">
      <a class="button primary" :href="`#/mesa/${campaign.id}`">{{ live ? 'Entrar na mesa' : 'Abrir a mesa' }}</a>
      <template v-if="isMaster">
        <a class="button" :href="`#/campanha/${campaign.id}/personagens`">Personagens e propostas</a>
        <a class="button" :href="`#/roteiro/${campaign.id}`">Roteiro</a>
        <a class="button" :href="`#/atlas/${campaign.id}`">Atlas</a>
        <a class="button" :href="`#/tablet/${campaign.id}`">Fichário (tablet)</a>
        <button type="button" class="ghost" :disabled="busy" @click="invite">Convidar jogadores</button>
        <button v-if="upgrades.length" type="button" class="ghost upgrade-btn" @click="upgradeTo = upgrades[upgrades.length - 1]!.id; upgrading = true">Atualizar sistema (v{{ upgrades[upgrades.length - 1]!.version }})</button>
      </template>
      <a v-else class="button" :href="`#/atlas/${campaign.id}`">O que sabemos</a>
    </footer>
    <div v-if="token" class="invite-box" role="region" aria-label="Convite">
      <p class="small">Envie este código ao jogador (vale 72 h, até 10 usos). Ele entra em "Entrar com convite".</p>
      <code class="invite-token">{{ token }}</code>
      <button type="button" class="ghost" @click="copy">{{ copied ? 'Copiado' : 'Copiar código' }}</button>
    </div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <ActionDialog v-if="upgrading" title="Atualizar o sistema da campanha" @close="upgrading = false" @submit="upgrade">
      <label>Nova versão da ficha
        <select v-model="upgradeTo"><option v-for="u in upgrades" :key="u.id" :value="u.id">v{{ u.version }}</option></select>
      </label>
      <p class="muted small">A campanha passa a usar a nova versão. Cada personagem continua na versão dele até ser adaptado pelo jogador ou por você; a adaptação mostra o que muda e volta para sua aprovação.</p>
      <template #actions><span class="grow" /><button type="button" class="ghost" @click="upgrading = false">Cancelar</button><button type="submit" class="primary" :disabled="busy">Atualizar</button></template>
    </ActionDialog>
  </li>
</template>

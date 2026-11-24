<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '../api/client'
import Portrait from '../components/live/Portrait.vue'
import AppShell from '../components/shell/AppShell.vue'
import ActionDialog from '../components/ui/ActionDialog.vue'
import { t } from '../i18n/messages'
import { campaigns as campaignsApi } from '../live/api'
import { DROP_REASON, setup, STATUS_LABEL, type AdaptationReport } from '../live/campaign'
import { meApi, type MyCharacter } from '../live/me'
import type { Campaign } from '../live/types'
import { go, me } from '../live/user'

/**
 * Every character the person plays, in every campaign, grouped by system. From here a character
 * is adapted to its campaign's current sheet version or brought into another campaign.
 */
const list = ref<MyCharacter[]>([])
const myCampaigns = ref<Campaign[]>([])
const loading = ref(true)
const error = ref('')
const status = ref('')

async function load(): Promise<void> {
  loading.value = true
  try {
    const [c, k] = await Promise.all([meApi.characters(), campaignsApi.list()])
    list.value = c.data
    myCampaigns.value = k.data
  } catch { error.value = 'Não foi possível carregar seus personagens.' } finally { loading.value = false }
}
onMounted(() => { if (!me.value) return go('#/'); void load() })

const groups = computed(() => {
  const by = new Map<string, { system: string; items: MyCharacter[] }>()
  for (const c of list.value) {
    const key = c.campaign_sheet?.definition_id ?? c.sheet?.definition_id ?? 'outro'
    const entry = by.get(key) ?? { system: c.campaign_sheet?.name || c.sheet?.name || 'Outro sistema', items: [] }
    entry.items.push(c)
    by.set(key, entry)
  }
  return [...by.values()].sort((a, b) => a.system.localeCompare(b.system))
})

// ---- Adapt to the campaign's sheet version ----
const adapting = ref<MyCharacter | null>(null)
const report = ref<AdaptationReport | null>(null)
const busy = ref(false)
async function openAdapt(c: MyCharacter): Promise<void> {
  adapting.value = c
  report.value = null
  try { report.value = await setup.adaptation(c.id) } catch (e) { error.value = t(e instanceof ApiError ? e.code : 'network.error'); adapting.value = null }
}
async function adapt(): Promise<void> {
  if (!adapting.value) return
  busy.value = true
  try {
    const current = await api<{ sheet_revision: number }>('GET', `/campaign-characters/${adapting.value.id}`)
    await setup.adapt(adapting.value.id, current.sheet_revision)
    status.value = `${adapting.value.name} agora usa a ficha v${report.value?.to_version}.${report.value?.needs_approval ? ' O mestre vai revisar de novo.' : ''}`
    adapting.value = null
    await load()
  } catch (e) { error.value = t(e instanceof ApiError ? e.code : 'network.error') } finally { busy.value = false }
}

// ---- Bring into another campaign ----
const bringing = ref<MyCharacter | null>(null)
const target = ref('')
const newName = ref('')
const targets = computed(() => myCampaigns.value.filter(c => c.role === 'player' && c.id !== bringing.value?.campaign.id))
function openBring(c: MyCharacter): void { bringing.value = c; newName.value = c.name; target.value = targets.value[0]?.id ?? '' }
async function bring(): Promise<void> {
  if (!bringing.value || !target.value) return
  busy.value = true
  try {
    const created = await setup.createCharacter(target.value, newName.value.trim() || bringing.value.name, crypto.randomUUID(), bringing.value.id)
    go(`#/personagens/${created.id}`)
  } catch (e) { error.value = t(e instanceof ApiError ? e.code : 'network.error') } finally { busy.value = false }
}
</script>

<template>
  <AppShell title="Personagens">
    <section class="mine">
      <header class="section-head">
        <h1>Seus personagens</h1>
        <p class="muted">Todos os personagens que você joga, em todas as campanhas. Cada um usa a ficha do sistema da campanha.</p>
      </header>
      <p v-if="status" class="status-banner" role="status">{{ status }}</p>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="loading" role="status">Carregando…</p>
      <p v-else-if="!list.length" class="empty-note">Você ainda não tem personagens. Entre numa campanha e crie o primeiro.</p>

      <section v-for="g in groups" :key="g.system" class="mine-group">
        <h2 class="group-title">{{ g.system }}</h2>
        <ul class="mine-grid">
          <li v-for="c in g.items" :key="c.id" class="mine-card" :class="{ outdated: c.outdated }">
            <Portrait :name="c.name" :image-id="c.portrait_image_id" :size="64" :ring="false" />
            <div class="mine-text">
              <a class="mine-name" :href="`#/personagens/${c.id}`">{{ c.name }}</a>
              <span class="mine-sub">{{ c.campaign.name }}</span>
              <span class="mine-tags">
                <span class="tag">ficha v{{ c.sheet?.version ?? '?' }}</span>
                <span class="status-pill" :class="c.status">{{ STATUS_LABEL[c.status] }}</span>
              </span>
              <span v-if="c.outdated" class="chip-warn">⚠ A campanha usa a ficha v{{ c.campaign_sheet?.version }}.</span>
            </div>
            <div class="mine-actions">
              <button v-if="c.outdated" type="button" class="primary small-btn" @click="openAdapt(c)">Adaptar à v{{ c.campaign_sheet?.version }}</button>
              <a class="button small-btn" :href="`#/personagens/${c.id}`">Abrir ficha</a>
              <button type="button" class="ghost small-btn" @click="openBring(c)">Levar para outra campanha</button>
            </div>
          </li>
        </ul>
      </section>
    </section>

    <ActionDialog v-if="adapting" :title="`Adaptar ${adapting.name}`" @close="adapting = null" @submit="adapt">
      <p v-if="!report" role="status">Conferindo o que muda…</p>
      <template v-else>
        <p>Da ficha <b>v{{ report.from_version }}</b> para a <b>v{{ report.to_version }}</b>. {{ report.kept }} valores continuam valendo.</p>
        <div v-if="report.dropped.length" class="drop-list">
          <p class="muted small">Saem da ficha (o resto fica como está):</p>
          <ul><li v-for="d in report.dropped" :key="d.path"><code>{{ d.path }}</code> — {{ DROP_REASON[d.reason] }}</li></ul>
        </div>
        <p v-else class="muted">Nada se perde.</p>
        <p v-if="report.draft_operations_dropped" class="muted small">{{ report.draft_operations_dropped }} alteração(ões) do seu rascunho deixam de valer.</p>
        <p v-if="report.needs_approval" class="chip-warn">A ficha volta para o mestre aprovar, porque as regras podem ter mudado.</p>
      </template>
      <template #actions>
        <span class="grow" />
        <button type="button" class="ghost" @click="adapting = null">Agora não</button>
        <button type="submit" class="primary" :disabled="!report || busy">Adaptar</button>
      </template>
    </ActionDialog>

    <ActionDialog v-if="bringing" :title="`Levar ${bringing.name}`" @close="bringing = null" @submit="bring">
      <label>Para a campanha
        <select v-model="target"><option v-for="c in targets" :key="c.id" :value="c.id">{{ c.name }}</option></select>
      </label>
      <p v-if="!targets.length" class="muted small">Você não joga em outra campanha. Entre com um convite primeiro.</p>
      <label>Nome nesta campanha <input v-model="newName" maxlength="120" /></label>
      <p class="muted small">Se a campanha usa o mesmo sistema, a ficha vai junto, adaptada à versão dela, e o mestre revisa os pontos. Se o sistema for outro, só o nome vai e a ficha começa do zero.</p>
      <template #actions>
        <span class="grow" />
        <button type="button" class="ghost" @click="bringing = null">Cancelar</button>
        <button type="submit" class="primary" :disabled="!target || busy">Levar</button>
      </template>
    </ActionDialog>
  </AppShell>
</template>

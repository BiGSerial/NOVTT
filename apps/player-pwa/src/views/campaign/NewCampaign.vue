<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '../../api/client'
import { t } from '../../i18n/messages'
import { presets, setup, type PresetSummary } from '../../live/campaign'
import PresetBadge from './PresetBadge.vue'

/** Master creates a campaign pinned to one preset version (canonical catalogue or own library). */
const emit = defineEmits<{ created: [id: string]; cancel: [] }>()
const list = ref<PresetSummary[]>([])
const chosen = ref<string | null>(null)
const name = ref('')
const description = ref('')
const error = ref('')
const busy = ref(false)
const loading = ref(true)
let key: string | null = null

const canonical = computed(() => list.value.filter(p => p.canonical))
const personal = computed(() => list.value.filter(p => !p.canonical))

onMounted(async () => {
  try { list.value = (await presets.list()).data } catch { error.value = 'Não foi possível carregar os presets.' } finally { loading.value = false }
})

async function create(): Promise<void> {
  error.value = ''
  if (!chosen.value) { error.value = 'Escolha um preset.'; return }
  if (!name.value.trim()) { error.value = 'Dê um nome à campanha.'; return }
  busy.value = true
  key ??= crypto.randomUUID()
  try {
    const campaign = await setup.create(name.value.trim(), description.value.trim() || null, chosen.value, key)
    key = null
    emit('created', campaign.id)
  } catch (e) {
    error.value = t(e instanceof ApiError ? e.code : 'network.error')
    if (e instanceof ApiError && e.status !== 0) key = null
  } finally { busy.value = false }
}
</script>

<template>
  <section class="new-campaign parchment-panel" aria-labelledby="new-campaign-title">
    <h2 id="new-campaign-title">Nova campanha</h2>
    <p class="muted small">O preset define a ficha, a criação, a evolução e as rolagens. A campanha fica presa à versão escolhida; versões novas não mudam fichas existentes.</p>
    <p v-if="loading" role="status">Carregando presets…</p>
    <fieldset v-else class="preset-choices">
      <legend>Sistema de regras</legend>
      <template v-for="group in [{ title: 'Catálogo NOVTT', items: canonical }, { title: 'Sua biblioteca', items: personal }]" :key="group.title">
        <p v-if="group.items.length" class="eyebrow">{{ group.title }}</p>
        <label v-for="p in group.items" :key="p.id" class="preset-option" :class="{ chosen: chosen === p.id }">
          <input v-model="chosen" type="radio" name="preset" :value="p.id" />
          <PresetBadge :preset="p" />
        </label>
      </template>
      <p v-if="!list.length" class="muted">Nenhum preset disponível.</p>
    </fieldset>
    <label class="field">Nome da campanha
      <input v-model="name" type="text" maxlength="120" autocomplete="off" />
    </label>
    <label class="field">Descrição (opcional)
      <textarea v-model="description" maxlength="4000" rows="3" />
    </label>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div class="card-actions">
      <button type="button" class="primary" :disabled="busy" @click="create">Criar campanha</button>
      <button type="button" class="ghost" :disabled="busy" @click="emit('cancel')">Cancelar</button>
    </div>
  </section>
</template>

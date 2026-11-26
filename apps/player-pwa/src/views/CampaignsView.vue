<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppShell from '../components/shell/AppShell.vue'
import { ApiError } from '../api/client'
import { t } from '../i18n/messages'
import { campaigns } from '../live/api'
import { setup } from '../live/campaign'
import { go, me } from '../live/user'
import type { Campaign } from '../live/types'
import CampaignCard from './campaign/CampaignCard.vue'
import NewCampaign from './campaign/NewCampaign.vue'

const list = ref<Campaign[]>([])
const loading = ref(true)
const error = ref('')
const creating = ref(false)
const inviteToken = ref('')
const joinStatus = ref('')
const joining = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try { list.value = (await campaigns.list()).data } catch { error.value = 'Não foi possível carregar as campanhas.' } finally { loading.value = false }
}

onMounted(() => {
  if (!me.value) return go('#/')
  void load()
})

async function join(): Promise<void> {
  const token = inviteToken.value.trim()
  if (!token) return
  joining.value = true
  joinStatus.value = ''
  try {
    await setup.accept(token)
    inviteToken.value = ''
    joinStatus.value = 'Você entrou na campanha.'
    await load()
  } catch (e) { joinStatus.value = t(e instanceof ApiError ? e.code : 'network.error') } finally { joining.value = false }
}

function created(): void {
  creating.value = false
  void load()
}

</script>

<template>
  <AppShell title="Campanhas">
  <div class="page wide campaigns-page">
    <div class="campaigns-head">
      <h1>Suas campanhas</h1>
      <button v-if="!creating" type="button" class="primary" @click="creating = true">Nova campanha</button>
    </div>
    <NewCampaign v-if="creating" @created="created" @cancel="creating = false" />
    <form class="join-form" @submit.prevent="join">
      <label class="field">Entrar com convite
        <input v-model="inviteToken" type="text" maxlength="128" autocomplete="off" spellcheck="false" placeholder="Cole o código enviado pelo mestre" />
      </label>
      <button type="submit" class="ghost" :disabled="joining || !inviteToken.trim()">Entrar</button>
      <p class="status small" role="status" aria-live="polite">{{ joinStatus }}</p>
    </form>
    <p v-if="loading" role="status">Carregando…</p>
    <p v-else-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="!list.length" class="muted">Você ainda não participa de nenhuma campanha. Crie uma ou entre com o convite do mestre.</p>
    <ul class="campaign-list">
      <CampaignCard v-for="c in list" :key="`${c.id}:${c.revision}`" :campaign="c" @changed="load" />
    </ul>
  </div>
  </AppShell>
</template>

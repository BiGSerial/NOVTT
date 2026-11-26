<script setup lang="ts">
import BrandMark from '../components/BrandMark.vue'
import { nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { api, ApiError, ensureCsrfCookie } from '../api/client'
import DiceTray from '../components/DiceTray.vue'
import { subscribeRolls } from '../dice/mirror'
import type { RollAnimationRecord } from '../dice/record'
import { t } from '../i18n/messages'
import { ReviewPanel, type ChangeRequestView, type Decision, type ReviewApi } from '../../../master-web/resources/js/review-panel'

/**
 * Development-only hub for the local demonstration (sh scripts/dev.sh demo). It only chooses
 * which seeded user the browser signs in as; every action afterwards uses the real /api/v1
 * routes, policies and review workflow.
 */
interface DemoState {
  seeded: boolean
  user: { id: string; name: string; role: 'master' | 'player' | null } | null
  campaign_id: string | null
  campaign_character_id: string | null
  active_change_request_id: string | null
}
interface Character { id: string; name: string; status: string; sheet_revision: number; sheet: unknown }

const props = defineProps<{ fetcher?: typeof fetch }>()
const DEMO = { base: '/api/dev/demo' }

const state = ref<DemoState | null>(null)
const unavailable = ref('')
const status = ref('')
const busy = ref(false)
const character = ref<Character | null>(null)
const reviewRoot = ref<HTMLElement | null>(null)
const hasReview = ref(false)

function message(error: unknown): string {
  return t(error instanceof ApiError ? error.code : 'network.error')
}

const reviewApi: ReviewApi = {
  async decide(requestId: string, reviewVersion: number, decisions: Decision[]) {
    try {
      return await api<ChangeRequestView>('PUT', `/change-requests/${requestId}/decisions`, { review_version: reviewVersion, decisions }, { fetcher: props.fetcher })
    } catch (error) { throw new Error(message(error)) }
  },
  async finalize(requestId: string, reviewVersion: number, idempotencyKey: string) {
    try {
      const view = await api<ChangeRequestView>('POST', `/change-requests/${requestId}/finalize`, { review_version: reviewVersion }, { idempotencyKey, fetcher: props.fetcher })
      void loadCharacter()
      return view
    } catch (error) { throw new Error(message(error)) }
  },
}

async function loadCharacter(): Promise<void> {
  const id = state.value?.campaign_character_id
  if (!id || !state.value?.user) return
  character.value = await api<Character>('GET', `/campaign-characters/${id}`, undefined, { fetcher: props.fetcher })
}

async function refresh(): Promise<void> {
  try {
    state.value = await api<DemoState>('GET', '', undefined, { ...DEMO, fetcher: props.fetcher })
  } catch (error) {
    unavailable.value = error instanceof ApiError && error.status === 404
      ? 'O modo demonstração está desativado. Rode sh scripts/dev.sh demo.'
      : message(error)
    return
  }
  character.value = null
  hasReview.value = false
  reviewRoot.value?.replaceChildren()
  try {
    await loadCharacter()
    if (state.value.user?.role === 'master' && state.value.active_change_request_id) {
      const view = await api<ChangeRequestView>('GET', `/change-requests/${state.value.active_change_request_id}`, undefined, { fetcher: props.fetcher })
      hasReview.value = true
      await nextTick()
      if (reviewRoot.value) new ReviewPanel(reviewRoot.value, view, reviewApi)
    }
  } catch (error) {
    status.value = message(error)
  }
}

async function signIn(role: 'master' | 'player'): Promise<void> {
  busy.value = true
  status.value = ''
  try {
    await ensureCsrfCookie(props.fetcher)
    await api('POST', '/session', { role }, { ...DEMO, fetcher: props.fetcher })
    await refresh()
    status.value = role === 'master' ? 'Você entrou como mestre.' : 'Você entrou como jogador.'
  } catch (error) {
    status.value = error instanceof ApiError && error.code === 'demo.not_seeded'
      ? 'A campanha de demonstração ainda não existe. Rode php artisan novtt:demo:seed.'
      : message(error)
  } finally {
    busy.value = false
  }
}

async function signOut(): Promise<void> {
  busy.value = true
  try {
    await api('DELETE', '/session', undefined, { ...DEMO, fetcher: props.fetcher })
    await refresh()
    status.value = 'Sessão encerrada.'
  } catch (error) {
    status.value = message(error)
  } finally {
    busy.value = false
  }
}

async function approveCharacter(): Promise<void> {
  const id = state.value?.campaign_character_id
  if (!id) return
  busy.value = true
  try {
    await api('POST', `/campaign-characters/${id}/approve`, undefined, { fetcher: props.fetcher })
    await loadCharacter()
    status.value = 'Personagem aprovado. Mudanças permanentes agora exigem concessão do mestre.'
  } catch (error) {
    status.value = message(error)
  } finally {
    busy.value = false
  }
}

// Rolls made by the player in another window of this browser replay here with the same seed,
// results and skin, so the master sees the same animation (local mirror; no server involved).
const mirrored = shallowRef<RollAnimationRecord | null>(null)
let unsubscribe: () => void = () => {}

onMounted(() => {
  void refresh()
  unsubscribe = subscribeRolls(record => { mirrored.value = record })
})
onBeforeUnmount(() => unsubscribe())
</script>

<template>
  <main class="page demo">
    <header>
      <BrandMark tagline />
      <p class="eyebrow">DEMONSTRAÇÃO LOCAL</p>
      <h1>Ficha e revisão</h1>
      <p class="banner">Somente desenvolvimento: usuários e dados fictícios, entrada sem senha. Não existe fora do ambiente local.</p>
    </header>

    <p v-if="unavailable" role="alert">{{ unavailable }}</p>
    <p v-else-if="!state" role="status" aria-live="polite">Carregando…</p>
    <template v-else>
      <p v-if="!state.seeded" role="alert">A campanha de demonstração ainda não existe. Rode php artisan novtt:demo:seed.</p>

      <section v-else-if="!state.user" class="panel" aria-labelledby="who-title">
        <h2 id="who-title">Entrar como</h2>
        <div class="roles">
          <button type="button" :disabled="busy" @click="signIn('player')">Jogador</button>
          <button type="button" class="secondary" :disabled="busy" @click="signIn('master')">Mestre</button>
        </div>
        <p class="help">O jogador edita o rascunho e envia a proposta; o mestre revisa operação por operação e finaliza.</p>
      </section>

      <template v-else>
        <section class="panel who" aria-label="Sessão atual">
          <p>Conectado como <strong>{{ state.user.name }}</strong></p>
          <button type="button" class="secondary" :disabled="busy" @click="signOut">Trocar de papel</button>
        </section>

        <section v-if="state.user.role === 'player'" class="panel" aria-labelledby="player-title">
          <h2 id="player-title">Sua ficha</h2>
          <p v-if="character">{{ character.name }} · revisão {{ character.sheet_revision }} · {{ character.status === 'approved' ? 'aprovado' : 'em criação' }}</p>
          <a class="cta" :href="`/#/personagens/${state.campaign_character_id}`">Abrir editor da ficha</a>
          <p class="help">Distribua pontos, salve e envie para o mestre. Depois volte aqui e troque para o papel de mestre.</p>
        </section>

        <template v-else-if="state.user.role === 'master'">
          <section class="panel review" aria-labelledby="review-title">
            <h2 id="review-title">Proposta ativa</h2>
            <p v-if="!state.active_change_request_id">Nenhuma proposta aguardando revisão.</p>
            <div v-show="hasReview" ref="reviewRoot"></div>
            <button type="button" class="secondary" :disabled="busy" @click="refresh">Atualizar</button>
          </section>
          <section class="panel" aria-labelledby="mirror-title">
            <h2 id="mirror-title">Dados do jogador</h2>
            <p class="help">Abra os dados livres (<a href="/">/</a>) em outra janela e role: a mesma animação aparece aqui. LOCAL · NÃO OFICIAL.</p>
            <p v-if="!mirrored">Nenhuma rolagem recebida ainda.</p>
            <DiceTray :record="mirrored" label="Espelho da mesa do jogador" />
          </section>
          <section v-if="character" class="panel" aria-labelledby="sheet-title">
            <h2 id="sheet-title">Ficha oficial de {{ character.name }}</h2>
            <p>Revisão {{ character.sheet_revision }} · {{ character.status === 'approved' ? 'aprovado' : 'em criação' }}</p>
            <button v-if="character.status !== 'approved'" type="button" :disabled="busy" @click="approveCharacter">Aprovar personagem</button>
            <details>
              <summary>Dados completos (visão do mestre)</summary>
              <pre>{{ JSON.stringify(character.sheet, null, 2) }}</pre>
            </details>
          </section>
        </template>
      </template>
      <p class="status" role="status" aria-live="polite">{{ status }}</p>
    </template>
  </main>
</template>

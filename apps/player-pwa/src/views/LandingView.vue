<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import { auth, demo } from '../live/api'
import { go, loadMe, me } from '../live/user'

/** Landing: what NOVTT is, sign in / sign up, and (local only) one-tap demo seats. */
const mode = ref<'login' | 'register'>('login')
const name = ref('')
const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')
const demoReady = ref(false)

onMounted(async () => {
  try { demoReady.value = (await demo.state()).seeded } catch { demoReady.value = false }
})

const MESSAGES: Record<string, string> = {
  'auth.invalid_credentials': 'Email ou senha não conferem.',
  'account.unavailable': 'Não foi possível criar a conta com esse email.',
  'validation.failed': 'Confira os campos: a senha precisa de pelo menos 10 caracteres.',
  'request.invalid': 'Confira os campos.',
  'http.429': 'Muitas tentativas. Espere um minuto.',
  'network.error': 'Sem conexão com o servidor.',
}

async function submit(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    if (mode.value === 'login') await auth.login(email.value, password.value)
    else await auth.register(name.value, email.value, password.value)
    await loadMe()
    go('#/campanhas')
  } catch (e) {
    error.value = MESSAGES[e instanceof ApiError ? e.code : 'network.error'] ?? 'Não foi possível entrar agora.'
  } finally {
    busy.value = false
  }
}

async function seat(role: 'master' | 'player' | 'player2' | 'player3' | 'player4' | 'player5'): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    await demo.login(role)
    const state = await demo.state()
    await loadMe()
    go(state.table_campaign_id ? `#/mesa/${state.table_campaign_id}` : '#/campanhas')
  } catch {
    error.value = 'A demonstração não está disponível.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="landing">
    <section class="hero">
      <BrandMark tagline class="hero-brand" />
      <h1>A mesa continua sendo de vocês.</h1>
      <p class="lead">NOVTT não substitui a mesa: ele cuida dos dados, das fichas e dos segredos para o mestre narrar e os jogadores jogarem.</p>
      <ul class="pillars">
        <li><strong>Dados que todos veem cair</strong><span>O servidor sorteia; os mesmos dados 3D rolam em todas as telas e param no mesmo resultado.</span></li>
        <li><strong>O mestre pede, o jogador rola</strong><span>Atributo + perícia, dados definidos pelo mestre, sem discussão de regra no meio da cena.</span></li>
        <li><strong>Segredos no lugar certo</strong><span>Sussurros, pistas e rolagens secretas chegam só a quem deve ver.</span></li>
      </ul>
      <p class="muted small"><a href="#/dados">Rolar dados livres sem conta</a> · <a href="#/tablet">Parear um tablet do mestre</a></p>
    </section>

    <section class="auth-panel" aria-labelledby="auth-title">
      <template v-if="me">
        <h2 id="auth-title">Olá, {{ me.name }}</h2>
        <button type="button" class="primary" @click="go('#/campanhas')">Ir para minhas campanhas</button>
      </template>
      <template v-else>
        <div class="tabs" role="tablist">
          <button type="button" role="tab" :aria-selected="mode === 'login'" :class="{ on: mode === 'login' }" @click="mode = 'login'">Entrar</button>
          <button type="button" role="tab" :aria-selected="mode === 'register'" :class="{ on: mode === 'register' }" @click="mode = 'register'">Criar conta</button>
        </div>
        <h2 id="auth-title" class="sr-only">{{ mode === 'login' ? 'Entrar' : 'Criar conta' }}</h2>
        <form @submit.prevent="submit">
          <label v-if="mode === 'register'">Nome <input v-model="name" autocomplete="name" required maxlength="80" /></label>
          <label>Email <input v-model="email" type="email" autocomplete="email" required maxlength="190" /></label>
          <label>Senha <input v-model="password" type="password" :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" required :minlength="mode === 'register' ? 10 : 1" maxlength="200" /></label>
          <p v-if="error" class="error" role="alert">{{ error }}</p>
          <button type="submit" class="primary" :disabled="busy">{{ mode === 'login' ? 'Entrar' : 'Criar conta e entrar' }}</button>
        </form>
        <div v-if="demoReady" class="demo-seats">
          <p class="eyebrow">Demonstração local</p>
          <p class="muted small">Abra cada lugar numa janela anônima ou noutro navegador.</p>
          <div class="seat-grid">
            <button type="button" class="seat master" :disabled="busy" @click="seat('master')">Mestre</button>
            <button type="button" class="seat" :disabled="busy" @click="seat('player')">Lyra <small>Ana</small></button>
            <button type="button" class="seat" :disabled="busy" @click="seat('player2')">Bram <small>Bruno</small></button>
            <button type="button" class="seat" :disabled="busy" @click="seat('player3')">Nix <small>Carla</small></button>
            <button type="button" class="seat" :disabled="busy" @click="seat('player4')">Tomé <small>Davi</small></button>
            <button type="button" class="seat" :disabled="busy" @click="seat('player5')">Selene <small>Eva</small></button>
          </div>
        </div>
      </template>
    </section>
  </main>
</template>

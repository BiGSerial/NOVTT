<script setup lang="ts">
import { onMounted, ref } from 'vue'
import BrandMark from '../components/BrandMark.vue'
import { auth, campaigns } from '../live/api'
import { go, me } from '../live/user'
import type { Campaign } from '../live/types'

const list = ref<Campaign[]>([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  if (!me.value) return go('#/')
  try { list.value = (await campaigns.list()).data } catch { error.value = 'Não foi possível carregar as campanhas.' } finally { loading.value = false }
})

async function logout(): Promise<void> {
  try { await auth.logout() } finally { me.value = null; go('#/') }
}
</script>

<template>
  <main class="page wide">
    <header class="topbar">
      <a href="#/" class="brand-link"><BrandMark /></a>
      <span class="spacer" />
      <span class="muted">{{ me?.name }}</span>
      <button type="button" class="ghost" @click="logout">Sair</button>
    </header>
    <h1>Suas campanhas</h1>
    <p v-if="loading" role="status">Carregando…</p>
    <p v-else-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="!list.length" class="muted">Você ainda não participa de nenhuma campanha. Peça um convite ao mestre.</p>
    <ul class="campaign-list">
      <li v-for="c in list" :key="c.id" class="campaign-card">
        <div>
          <p class="eyebrow">{{ c.role === 'master' ? 'Você mestra' : 'Você joga' }}</p>
          <h2>{{ c.name }}</h2>
          <p v-if="c.description" class="muted small">{{ c.description }}</p>
        </div>
        <div class="card-actions">
          <a class="button primary" :href="`#/mesa/${c.id}`">Abrir a mesa</a>
          <template v-if="c.role === 'master'">
            <a class="button" :href="`#/roteiro/${c.id}`">Roteiro</a>
            <a class="button" :href="`#/atlas/${c.id}`">Atlas</a>
            <a class="button" :href="`#/tablet/${c.id}`">Fichário (tablet)</a>
          </template>
          <a v-else class="button" :href="`#/atlas/${c.id}`">O que sabemos</a>
        </div>
      </li>
    </ul>
  </main>
</template>

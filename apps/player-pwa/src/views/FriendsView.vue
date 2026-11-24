<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Portrait from '../components/live/Portrait.vue'
import AppShell from '../components/shell/AppShell.vue'
import { meApi, type Companion } from '../live/me'
import { go, me } from '../live/user'

/**
 * The people you play with (fellow members of your campaigns), online first. Presence is coarse
 * on purpose: online now, or the day last seen. Refreshes every minute while open.
 */
const people = ref<Companion[]>([])
const loading = ref(true)
const error = ref('')
let timer: ReturnType<typeof setInterval> | undefined
async function load(): Promise<void> {
  try { people.value = (await meApi.companions()).data; error.value = '' } catch { error.value = 'Não foi possível carregar.' } finally { loading.value = false }
}
onMounted(() => { if (!me.value) return go('#/'); void load(); timer = setInterval(() => { if (!document.hidden) void load() }, 60_000) })
onBeforeUnmount(() => clearInterval(timer))
function seen(day: string | null): string {
  if (!day) return 'ainda não entrou'
  const today = new Date().toISOString().slice(0, 10)
  const yesterday = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10)
  if (day === today) return 'visto hoje'
  if (day === yesterday) return 'visto ontem'
  return `visto em ${new Date(`${day}T12:00:00Z`).toLocaleDateString('pt-BR', { day: 'numeric', month: 'short' })}`
}
</script>

<template>
  <AppShell title="Amigos">
    <section class="friends">
      <header class="section-head">
        <h1>Com quem você joga</h1>
        <p class="muted">Pessoas das suas campanhas. Quem está online aparece primeiro.</p>
      </header>
      <p v-if="loading" role="status">Carregando…</p>
      <p v-else-if="error" class="error" role="alert">{{ error }}</p>
      <p v-else-if="!people.length" class="empty-note">Quando você entrar numa campanha, os outros jogadores e o mestre aparecem aqui.</p>
      <ul class="friend-list">
        <li v-for="p in people" :key="p.user_id" class="friend" :class="{ online: p.online }">
          <span class="friend-avatar"><Portrait :name="p.name" :size="48" :ring="false" /><span class="presence-dot" :title="p.online ? 'Online' : 'Offline'" /></span>
          <span class="friend-text">
            <b>{{ p.name }}</b>
            <small>{{ p.online ? 'online agora' : seen(p.last_seen_on) }}</small>
          </span>
          <span class="friend-campaigns">
            <a v-for="c in p.campaigns" :key="c.id" class="tag" :href="`#/mesa/${c.id}`" :title="c.role === 'master' ? 'Mestre desta campanha' : 'Joga nesta campanha'">{{ c.role === 'master' ? '♛ ' : '' }}{{ c.name }}</a>
          </span>
        </li>
      </ul>
      <p class="muted small">Convites de amizade fora das campanhas chegam numa próxima etapa.</p>
    </section>
  </AppShell>
</template>

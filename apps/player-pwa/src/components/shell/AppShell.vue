<script setup lang="ts">
import { computed, ref } from 'vue'
import BrandMark from '../BrandMark.vue'
import { auth } from '../../live/api'
import { usePlatform } from '../../live/platform'
import { go, me } from '../../live/user'

/**
 * The app frame outside the table (campaigns, characters, friends, dice, settings).
 * Web: top bar with the sections and the account menu. Installed PWA / Android on phones and
 * tablets: compact app bar + bottom navigation (thumb reach, safe areas); desktop installs keep
 * the top bar. The table views are full-screen and do not use this frame.
 */
const props = defineProps<{ title?: string }>()
const { platform, bottomNav } = usePlatform()
const NAV = [
  { hash: '#/campanhas', label: 'Campanhas', icon: '⚑' },
  { hash: '#/personagens', label: 'Personagens', icon: '✦' },
  { hash: '#/amigos', label: 'Amigos', icon: '☺' },
  { hash: '#/dados', label: 'Dados', icon: '⬡' },
  { hash: '#/ajustes', label: 'Ajustes', icon: '⚙' },
] as const
const current = ref(location.hash.replace(/\/$/, ''))
const active = (hash: string) => current.value === hash || current.value.startsWith(`${hash}/`)
const menu = ref(false)
const initials = computed(() => (me.value?.name ?? '?').replace(/\(.*?\)/g, '').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase())
async function logout(): Promise<void> {
  try { await auth.logout() } finally { me.value = null; go('#/') }
}
void props
</script>

<template>
  <div class="shell" :class="[`on-${platform}`, { 'has-bottom-nav': bottomNav }]">
    <header class="shell-bar">
      <a href="#/campanhas" class="brand-link" aria-label="Início"><BrandMark /></a>
      <h1 v-if="bottomNav && title" class="shell-title">{{ title }}</h1>
      <nav v-if="!bottomNav" class="shell-nav" aria-label="Seções">
        <a v-for="n in NAV" :key="n.hash" :href="n.hash" :aria-current="active(n.hash) ? 'page' : undefined">{{ n.label }}</a>
      </nav>
      <span class="spacer" />
      <div class="shell-account">
        <button type="button" class="avatar-btn" :aria-expanded="menu" aria-haspopup="menu" :title="me?.name" @click="menu = !menu">{{ initials }}</button>
        <div v-if="menu" class="account-menu" role="menu" @keydown.escape="menu = false">
          <p class="account-name">{{ me?.name }}</p>
          <a role="menuitem" href="#/ajustes" @click="menu = false">Ajustes</a>
          <button role="menuitem" type="button" @click="logout">Sair</button>
        </div>
      </div>
    </header>
    <main class="shell-main">
      <slot />
    </main>
    <nav v-if="bottomNav" class="bottom-nav" aria-label="Seções">
      <a v-for="n in NAV" :key="n.hash" :href="n.hash" :aria-current="active(n.hash) ? 'page' : undefined">
        <span class="bn-icon" aria-hidden="true">{{ n.icon }}</span><span class="bn-label">{{ n.label }}</span>
      </a>
    </nav>
  </div>
</template>

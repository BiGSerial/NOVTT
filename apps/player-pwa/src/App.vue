<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import CharacterDraftView from './views/CharacterDraftView.vue'
import FreeDiceView from './views/FreeDiceView.vue'

// Minimal hash routing: #/personagens/<uuid> opens the sheet editor; anything else shows free dice.
const route = ref(location.hash)
const onHash = (): void => { route.value = location.hash }
window.addEventListener('hashchange', onHash)
onBeforeUnmount(() => window.removeEventListener('hashchange', onHash))
const characterId = computed(() => /^#\/personagens\/([0-9a-f-]{36})$/i.exec(route.value)?.[1] ?? null)
</script>

<template>
  <CharacterDraftView v-if="characterId" :character-id="characterId" />
  <FreeDiceView v-else />
</template>

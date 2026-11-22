<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import CharacterDraftView from './views/CharacterDraftView.vue'

// Minimal hash routing: #/personagens/<uuid> opens the sheet editor; anything else shows free dice.
const route = ref(location.hash)
const onHash = (): void => { route.value = location.hash }
window.addEventListener('hashchange', onHash)
onBeforeUnmount(() => window.removeEventListener('hashchange', onHash))
const characterId = computed(() => /^#\/personagens\/([0-9a-f-]{36})$/i.exec(route.value)?.[1] ?? null)

const count = ref(3)
const faces = ref(10)
const result = ref<number[]>([])
const error = ref('')
const total = computed(() => result.value.reduce((sum, value) => sum + value, 0))

function secureDie(facesCount: number): number {
  if (!Number.isSafeInteger(facesCount) || facesCount < 2 || facesCount > 1000) {
    throw new Error('Número de faces inválido.')
  }
  const range = 0x100000000
  const ceiling = range - (range % facesCount)
  const buffer = new Uint32Array(1)
  do {
    crypto.getRandomValues(buffer)
  } while (buffer[0] >= ceiling)
  return (buffer[0] % facesCount) + 1
}

function roll(): void {
  error.value = ''
  if (!Number.isSafeInteger(count.value) || count.value < 1 || count.value > 100) {
    error.value = 'Escolha de 1 a 100 dados.'
    return
  }
  try {
    result.value = Array.from({ length: count.value }, () => secureDie(faces.value))
  } catch {
    error.value = 'Não foi possível gerar dados seguros neste navegador.'
  }
}
</script>

<template>
  <CharacterDraftView v-if="characterId" :character-id="characterId" />
  <main v-else class="page">
    <header>
      <p class="eyebrow">NOVTT PLAYER</p>
      <h1>Dados livres</h1>
      <p>Role dados sem precisar entrar em uma campanha.</p>
    </header>
    <section class="panel" aria-label="Configuração dos dados">
      <p class="notice">LOCAL · NÃO OFICIAL</p>
      <label>Quantidade de dados
        <input v-model.number="count" type="number" min="1" max="100" inputmode="numeric" />
      </label>
      <label>Faces por dado
        <select v-model.number="faces">
          <option v-for="face in [4, 6, 8, 10, 12, 20, 100]" :key="face" :value="face">d{{ face }}</option>
        </select>
      </label>
      <button type="button" @click="roll">Rolar dados</button>
      <p v-if="error" role="alert">{{ error }}</p>
      <section v-if="result.length" aria-live="polite">
        <h2>Resultado: {{ total }}</h2>
        <p class="dice">{{ result.join(' · ') }}</p>
      </section>
    </section>
    <p class="footnote">Rolagens locais não são válidas em sessões mestradas.</p>
  </main>
</template>

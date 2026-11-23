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

<style>
:root { font-family: system-ui, sans-serif; color: #f9fafb; background: #111827; }
* { box-sizing: border-box; }
body { margin: 0; }
.page { width: min(100%, 520px); margin: 0 auto; padding: 32px 20px; }
.eyebrow { font-size: 12px; letter-spacing: .18em; color: #a5b4fc; font-weight: 700; }
h1 { font-size: 32px; margin: 8px 0; }
header p:last-child, .footnote { color: #cbd5e1; }
.panel { margin-top: 32px; padding: 24px; border: 1px solid #374151; border-radius: 20px; background: #1f2937; }
.notice { color: #fbbf24; font-size: 12px; font-weight: 700; letter-spacing: .08em; }
label { display: grid; gap: 8px; margin: 18px 0; font-weight: 600; }
input, select { width: 100%; min-height: 48px; border-radius: 10px; border: 1px solid #6b7280; padding: 10px; color: white; background: #111827; font-size: 18px; }
button { width: 100%; padding: 14px; border: 0; border-radius: 10px; background: #a5b4fc; color: #111827; font-weight: 800; font-size: 17px; min-height: 44px; }
button:disabled { opacity: .55; cursor: not-allowed; }
button.secondary { background: transparent; color: #e0e7ff; border: 1px solid #a5b4fc; }
:focus-visible { outline: 3px solid #fbbf24; outline-offset: 2px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.section { margin: 20px 0; padding: 16px; border: 1px solid #374151; border-radius: 14px; }
legend { font-weight: 800; padding: 0 6px; }
.field { margin: 14px 0; }
.field-label { display: block; font-weight: 600; margin-bottom: 6px; }
.help { color: #cbd5e1; font-size: 14px; }
.error { color: #fca5a5; font-weight: 600; }
.field.invalid input, .field.invalid textarea { border-color: #fca5a5; }
.error-summary { border: 2px solid #fca5a5; border-radius: 12px; padding: 12px 16px; }
.error-summary a { color: #fecaca; }
.dot-rating [role=radiogroup] { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.dot { width: 44px; height: 44px; min-height: 44px; padding: 0; border-radius: 50%; border: 2px solid #a5b4fc; background: transparent; }
.dot.filled { background: #a5b4fc; }
.dot.zero { border-style: dashed; }
.dot-value { margin-left: 8px; font-weight: 700; }
.readonly { margin: 0; } .readonly dt { font-weight: 600; } .readonly dd { margin: 4px 0 0; }
.collection ul { padding-left: 18px; } .item-field { margin-right: 12px; }
.actions { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; margin-top: 20px; }
@media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
.dice { overflow-wrap: anywhere; line-height: 1.8; font-size: 20px; }
.footnote { font-size: 13px; }
</style>

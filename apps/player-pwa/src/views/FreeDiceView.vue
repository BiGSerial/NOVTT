<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
import DiceTray from '../components/DiceTray.vue'
import SkinPicker from '../components/SkinPicker.vue'
import { loadDiceStage } from '../dice/loader'
import { publishRoll } from '../dice/mirror'
import { createLocalRoll, maxCount, ROLL_TYPES, type RollAnimationRecord, type RollType } from '../dice/record'
import { loadSkin, saveSkin, type DiceSkin } from '../dice/skins'
import { unlockAudio } from '../dice/sound'

/** Standalone free dice (PC-03/PC-04): crypto results, LOCAL / UNOFFICIAL, up to 6 dice on the table. */
defineProps<{ loader?: typeof loadDiceStage }>()

const FEEDBACK_KEY = 'novtt.dice.feedback'
function loadFeedback(): { sound: boolean; haptics: boolean } {
  try {
    const raw = JSON.parse(localStorage.getItem(FEEDBACK_KEY) ?? 'null') as unknown
    if (raw && typeof raw === 'object' && typeof (raw as { sound?: unknown }).sound === 'boolean') {
      const r = raw as { sound: boolean; haptics?: unknown }
      return { sound: r.sound, haptics: typeof r.haptics === 'boolean' ? r.haptics : r.sound }
    }
  } catch { /* preference only */ }
  return { sound: true, haptics: true }
}

const type = ref<RollType>('d20')
const count = ref(1)
const skin = ref<DiceSkin>(loadSkin())
const feedback = ref(loadFeedback())
const record = shallowRef<RollAnimationRecord | null>(null)
const error = ref('')
const limit = computed(() => maxCount(type.value))

watch(skin, s => saveSkin(s))
watch(feedback, f => { try { localStorage.setItem(FEEDBACK_KEY, JSON.stringify(f)) } catch { /* preference only */ } }, { deep: true })
// A d100 uses two dice on the table: keep the count within what fits.
watch(type, () => { if (count.value > limit.value) count.value = limit.value })

function roll(): void {
  error.value = ''
  if (feedback.value.sound) unlockAudio()
  try {
    // A new roll replaces the current one at once, even mid-animation.
    record.value = createLocalRoll(type.value, count.value, skin.value)
  } catch (e) {
    error.value = e instanceof RangeError ? e.message : 'Não foi possível gerar dados seguros neste navegador.'
    return
  }
  try {
    publishRoll(record.value)
  } catch {
    // The same-browser mirror is best effort; the roll itself is already complete.
  }
}
</script>

<template>
  <main class="page dice-page">
    <header>
      <p class="eyebrow">NOVTT PLAYER</p>
      <h1>Dados livres</h1>
      <p>Role dados sem precisar entrar em uma campanha.</p>
    </header>
    <section class="panel" aria-label="Configuração dos dados">
      <p class="notice">LOCAL · NÃO OFICIAL</p>
      <fieldset class="choice">
        <legend>Dado</legend>
        <div class="segmented">
          <label v-for="t in ROLL_TYPES" :key="t"><input v-model="type" type="radio" name="die-type" :value="t" /><span>{{ t }}</span></label>
        </div>
      </fieldset>
      <fieldset class="choice">
        <legend>Quantidade (até {{ limit }})</legend>
        <div class="segmented">
          <label v-for="n in limit" :key="n"><input v-model.number="count" type="radio" name="die-count" :value="n" /><span>{{ n }}</span></label>
        </div>
      </fieldset>
      <div class="roll-row">
        <button type="button" class="roll" @click="roll">Rolar {{ count }}{{ type }}</button>
        <button
          type="button" class="secondary toggle" :aria-pressed="feedback.sound"
          @click="feedback = { sound: !feedback.sound, haptics: !feedback.sound }"
        >{{ feedback.sound ? 'Som ligado' : 'Som desligado' }}</button>
      </div>
      <p v-if="error" role="alert">{{ error }}</p>
    </section>
    <DiceTray :record="record" :loader="loader" :feedback="feedback" :warm-skin="skin" surface="overlay" label="Resultado dos dados livres" />
    <SkinPicker v-model="skin" />
    <p class="footnote">Rolagens locais não são válidas em sessões mestradas.</p>
  </main>
</template>

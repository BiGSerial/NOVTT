<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import DiceTray from '../components/DiceTray.vue'
import SkinPicker from '../components/SkinPicker.vue'
import type { DieType } from '../dice/geometry'
import { loadDiceStage } from '../dice/loader'
import { publishRoll } from '../dice/mirror'
import { createLocalRoll, MAX_DICE, SUPPORTED_TYPES, type RollAnimationRecord } from '../dice/record'
import { loadSkin, saveSkin, type DiceSkin } from '../dice/skins'

/** Standalone free dice (PC-03/PC-04): crypto results, LOCAL / UNOFFICIAL, up to 6 dice. */
defineProps<{ loader?: typeof loadDiceStage }>()
const type = ref<DieType>('d20')
const count = ref(1)
const skin = ref<DiceSkin>(loadSkin())
const record = shallowRef<RollAnimationRecord | null>(null)
const error = ref('')
const busy = ref(false)

watch(skin, s => saveSkin(s))

function roll(): void {
  error.value = ''
  try {
    record.value = createLocalRoll(type.value, count.value, skin.value)
  } catch (e) {
    error.value = e instanceof RangeError ? e.message : 'Não foi possível gerar dados seguros neste navegador.'
    return
  }
  busy.value = true
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
          <label v-for="t in SUPPORTED_TYPES" :key="t"><input v-model="type" type="radio" name="die-type" :value="t" /><span>{{ t }}</span></label>
        </div>
      </fieldset>
      <fieldset class="choice">
        <legend>Quantidade (até {{ MAX_DICE }})</legend>
        <div class="segmented">
          <label v-for="n in MAX_DICE" :key="n"><input v-model.number="count" type="radio" name="die-count" :value="n" /><span>{{ n }}</span></label>
        </div>
      </fieldset>
      <button type="button" :disabled="busy" @click="roll">Rolar {{ count }}{{ type }}</button>
      <p v-if="error" role="alert">{{ error }}</p>
    </section>
    <DiceTray :record="record" :loader="loader" label="Mesa de dados livres" @settled="busy = false" />
    <SkinPicker v-model="skin" />
    <p class="footnote">Rolagens locais não são válidas em sessões mestradas.</p>
  </main>
</template>

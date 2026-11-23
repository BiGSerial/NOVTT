<script setup lang="ts">
import { computed, ref } from 'vue'
import { contrast, FINISH_LABELS, FINISHES, MIN_CONTRAST, parseSkin, PRESET_SKINS, type DiceSkin, type Finish } from '../dice/skins'

/** Chooses a dice skin: presets or a custom pair of colours with a legibility (contrast) check. */
const props = defineProps<{ modelValue: DiceSkin }>()
const emit = defineEmits<{ 'update:modelValue': [skin: DiceSkin] }>()

const body = ref(props.modelValue.body)
const ink = ref(props.modelValue.ink)
const finish = ref<Finish>(props.modelValue.finish)
const ratio = computed(() => contrast(body.value, ink.value))
const legible = computed(() => ratio.value >= MIN_CONTRAST)

function choose(skin: DiceSkin): void {
  body.value = skin.body
  ink.value = skin.ink
  finish.value = skin.finish
  emit('update:modelValue', skin)
}

function applyCustom(): void {
  const skin = parseSkin({ id: 'personalizado', name: 'Personalizado', body: body.value, ink: ink.value, finish: finish.value })
  if (skin) emit('update:modelValue', skin)
}
</script>

<template>
  <fieldset class="skin-picker">
    <legend>Aparência dos dados</legend>
    <div class="skins" role="radiogroup" aria-label="Estilos prontos">
      <button
        v-for="skin in PRESET_SKINS" :key="skin.id" type="button" role="radio"
        :aria-checked="modelValue.id === skin.id" class="skin" @click="choose(skin)"
      >
        <span class="swatch" :style="{ background: skin.body, color: skin.ink }" aria-hidden="true">20</span>
        {{ skin.name }}
      </button>
    </div>
    <details>
      <summary>Personalizar</summary>
      <label>Cor do dado <input v-model="body" type="color" @change="applyCustom" /></label>
      <label>Cor dos números <input v-model="ink" type="color" @change="applyCustom" /></label>
      <label>Acabamento
        <select v-model="finish" @change="applyCustom">
          <option v-for="f in FINISHES" :key="f" :value="f">{{ FINISH_LABELS[f] }}</option>
        </select>
      </label>
      <p :class="legible ? 'help' : 'error'" aria-live="polite">
        Contraste {{ ratio.toFixed(1) }}:1 —
        {{ legible ? 'números legíveis.' : `pouco legível; use pelo menos ${MIN_CONTRAST}:1.` }}
      </p>
    </details>
  </fieldset>
</template>

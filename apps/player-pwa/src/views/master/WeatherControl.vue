<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { currentHour, formatHour, phaseOf, TIME_PRESETS, WEATHER_LABELS, WEATHERS, type Weather } from '../../live/atmosphere'
import { useMaster } from './context'

/**
 * The master's weather and time-of-day control (SPEC-MVP-003 NT-10). Every change previews at
 * once on this screen and reaches the players' screens; the sky animates over the chosen
 * transition. "O tempo passa" lets the sun and the moon cross the sky on their own, the same on
 * every screen.
 */
const m = useMaster()
const open = ref(false)
const a = computed(() => m.atmosphere.value)
const now = ref(Date.now())
const clock = setInterval(() => { now.value = Date.now() }, 5000)
onBeforeUnmount(() => clearInterval(clock))
const hour = computed(() => currentHour(a.value, now.value))

const ICONS: Record<Weather, string> = { clear: '☀', clouds: '☁', rain: '🌧', storm: '⛈', fog: '🌫', snow: '❄' }
const SPEEDS = [
  { v: 0, label: 'Parado' },
  { v: 10, label: 'Devagar (10 min por minuto)' },
  { v: 30, label: 'Normal (½ hora por minuto)' },
  { v: 60, label: 'Rápido (1 hora por minuto)' },
  { v: 120, label: 'Muito rápido (2 horas por minuto)' },
]
const TRANSITIONS = [
  { v: 0, label: 'Na hora' },
  { v: 4, label: 'Suave (4 s)' },
  { v: 12, label: 'Lenta (12 s)' },
  { v: 30, label: 'Bem lenta (30 s)' },
]

const setHour = (h: number) => m.setAtmosphere({ hour: Math.round(h * 4) / 4 % 24 })
</script>

<template>
  <div class="weather-wrap">
    <button type="button" class="ghost on-art weather-btn" :aria-expanded="open" aria-controls="weather-panel" @click="open = !open">
      <span aria-hidden="true">{{ ICONS[a.weather] }}</span> {{ formatHour(hour) }} · {{ WEATHER_LABELS[a.weather] }}
    </button>
    <Teleport to="body">
    <div v-if="open" id="weather-panel" class="weather-panel" role="dialog" aria-label="Clima e hora da cena" @keydown.escape="open = false">
      <div class="weather-now">
        <b>{{ formatHour(hour) }}</b><span>{{ phaseOf(hour) }} · {{ WEATHER_LABELS[a.weather] }}</span>
      </div>

      <label>Hora do dia
        <input type="range" min="0" max="23.75" step="0.25" :value="hour" :aria-valuetext="`${formatHour(hour)}, ${phaseOf(hour)}`" @input="setHour(Number(($event.target as HTMLInputElement).value))" />
        <span class="hour-track" aria-hidden="true" />
      </label>
      <div class="weather-row" role="group" aria-label="Atalhos de hora">
        <button v-for="p in TIME_PRESETS" :key="p.hour" type="button" class="chip" :class="{ on: phaseOf(hour) === phaseOf(p.hour) }" @click="setHour(p.hour)">{{ p.label }}</button>
      </div>

      <div class="weather-row" role="radiogroup" aria-label="Tempo">
        <button v-for="w in WEATHERS" :key="w" type="button" role="radio" class="chip" :class="{ on: a.weather === w }" :aria-checked="a.weather === w" @click="m.setAtmosphere({ weather: w })">
          <span aria-hidden="true">{{ ICONS[w] }}</span> {{ WEATHER_LABELS[w] }}
        </button>
      </div>

      <label v-if="a.weather !== 'clear'">Intensidade
        <input type="range" min="0.1" max="1" step="0.05" :value="a.intensity" :aria-valuetext="`${Math.round(a.intensity * 100)}%`" @input="m.setAtmosphere({ intensity: Number(($event.target as HTMLInputElement).value) })" />
      </label>

      <label>O tempo passa
        <select :value="a.time_scale" @change="m.setAtmosphere({ hour: Math.round(hour * 4) / 4 % 24, time_scale: Number(($event.target as HTMLSelectElement).value) })">
          <option v-for="s in SPEEDS" :key="s.v" :value="s.v">{{ s.label }}</option>
        </select>
      </label>
      <label>Transição
        <select :value="a.transition_seconds" @change="m.setAtmosphere({ transition_seconds: Number(($event.target as HTMLSelectElement).value) })">
          <option v-for="t in TRANSITIONS" :key="t.v" :value="t.v">{{ t.label }}</option>
        </select>
      </label>
      <p class="help">Os jogadores veem o mesmo céu. Mudanças de tempo e de período aparecem para eles como um aviso curto.</p>
      <button type="button" class="ghost" @click="open = false">Fechar</button>
    </div>
    </Teleport>
  </div>
</template>

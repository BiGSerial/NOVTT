<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { loadDiceStage } from '../dice/loader'
import type { RollAnimationRecord } from '../dice/record'
import type { DiceSkin } from '../dice/skins'
import type { AnimationMode, DiceAnimator, Surface } from '../dice/stage'

/**
 * 3D dice tray (fixed top-down view). The text result is always present and is the source of
 * truth for assistive technology; the canvas is decorative (aria-hidden). Falls back to text
 * when WebGL or the 3D bundle is unavailable (e.g. offline before the first 3D use).
 */
const props = defineProps<{
  record: RollAnimationRecord | null
  label?: string
  loader?: typeof loadDiceStage
  instant?: boolean
  /** Sound and vibration on impacts (presentation only). */
  feedback?: { sound: boolean; haptics: boolean }
  /** Skin to pre-compile while idle (the next roll's likely skin). */
  warmSkin?: DiceSkin
  /** 'overlay': the dice roll over the whole page (transparent, no tray) and then fade away. */
  surface?: Surface
}>()
const emit = defineEmits<{ settled: [record: RollAnimationRecord] }>()

const host = ref<HTMLElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const phase = ref<'idle' | 'loading' | 'rolling' | 'done'>('idle')
const unavailable = ref(false)
const order = ref<number[]>([])
const announced = ref('')
const stats = ref('')
let animator: DiceAnimator | null = null
const ready = ref(false)
let observer: ResizeObserver | null = null
let token = 0
const overlay = computed(() => props.surface === 'overlay')
/** Overlay only: dice stay readable for a moment after settling, then leave the page uncovered. */
const OVERLAY_HOLD_MS = 3500
const OVERLAY_FADE_MS = 450
const covering = ref(false)
const fading = ref(false)
let dismissTimer: ReturnType<typeof setTimeout> | undefined

function dismiss(): void {
  clearTimeout(dismissTimer)
  if (!covering.value) return
  fading.value = true
  dismissTimer = setTimeout(() => {
    covering.value = false
    fading.value = false
    animator?.clear()
  }, reducedMotion() ? 0 : OVERLAY_FADE_MS)
}
function onPageInput(event: Event): void {
  if (phase.value === 'done' && !(event instanceof KeyboardEvent && event.key !== 'Escape')) dismiss()
}

const reducedMotion = (): boolean => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches

const ordered = computed(() => {
  const r = props.record
  if (!r) return []
  const idx = order.value.length === r.dice.length ? order.value : r.dice.map((_, i) => i)
  return idx.map(i => r.dice[i])
})
const total = computed(() => ordered.value.reduce((s, d) => s + d.result, 0))

function summary(r: RollAnimationRecord): string {
  const values = ordered.value.map(d => d.result).join(', ')
  return `${r.dice.length}${r.dice[0].type}: ${values}${r.dice.length > 1 ? ` (soma ${total.value})` : ''}`
}

let pending: Promise<DiceAnimator | null> | null = null

function ensureAnimator(): Promise<DiceAnimator | null> {
  if (animator || unavailable.value) return Promise.resolve(animator)
  if (!canvas.value || !host.value) return Promise.resolve(null)
  pending ??= (async () => {
    try {
      const stage = await (props.loader ?? loadDiceStage)()
      if (!canvas.value || !host.value) return null
      animator = stage.create(canvas.value, props.surface ?? 'tray')
      ready.value = true
      if (props.feedback) animator.feedback = { ...props.feedback }
      const resize = () => animator?.resize(host.value!.clientWidth, host.value!.clientHeight)
      resize()
      if (typeof ResizeObserver !== 'undefined') { observer = new ResizeObserver(resize); observer.observe(host.value) }
    } catch {
      unavailable.value = true
      animator = null
    }
    return animator
  })()
  return pending
}

/**
 * Warm-up: load the 3D stack while the user is still choosing, so the first roll starts at once.
 * Skipped when the user asked the browser to save data (it then loads on the first roll).
 */
function preload(): void {
  const saveData = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection?.saveData === true
  if (saveData) return
  const idle = (globalThis as { requestIdleCallback?: (cb: () => void, o?: { timeout: number }) => number }).requestIdleCallback
  const warm = async () => {
    const a = await ensureAnimator()
    if (!a || !props.warmSkin) return
    const run = () => { try { a.warmUp(props.warmSkin!) } catch { /* best effort */ } }
    if (idle) idle(run, { timeout: 1500 }); else setTimeout(run, 50)
  }
  if (idle) idle(() => { void warm() }, { timeout: 1500 })
  else setTimeout(() => { void warm() }, 300)
}

watch(() => props.feedback, f => { if (animator && f) animator.feedback = { ...f } }, { deep: true })

async function show(record: RollAnimationRecord | null): Promise<void> {
  const mine = ++token
  order.value = []
  announced.value = ''
  clearTimeout(dismissTimer)
  fading.value = false
  if (!record) { phase.value = 'idle'; covering.value = false; animator?.clear(); return }
  phase.value = animator ? 'rolling' : 'loading'
  covering.value = overlay.value
  // Bring the tray into view on small screens (the roll button sits above it).
  if (!overlay.value) host.value?.scrollIntoView?.({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' })
  const a = await ensureAnimator()
  if (mine !== token) return
  if (a) {
    phase.value = 'rolling'
    const mode: AnimationMode = props.instant ? 'instant' : reducedMotion() ? 'reduced' : 'animated'
    try {
      const s = await a.play(record, mode)
      if (mine !== token) return
      order.value = s.order
      stats.value = `cálculo ${s.simulationMs.toFixed(0)} ms · animação ${s.animationS.toFixed(1)} s`
    } catch {
      unavailable.value = true
    }
  }
  if (mine !== token) return
  phase.value = 'done'
  announced.value = `Resultado: ${summary(record)}`
  if (overlay.value) dismissTimer = setTimeout(dismiss, OVERLAY_HOLD_MS)
  emit('settled', record)
}

watch(() => props.record, r => { void show(r) })
onMounted(() => {
  if (props.record) void show(props.record); else preload()
  if (overlay.value) { document.addEventListener('pointerdown', onPageInput, true); document.addEventListener('keydown', onPageInput, true) }
})
onBeforeUnmount(() => {
  token++
  clearTimeout(dismissTimer)
  document.removeEventListener('pointerdown', onPageInput, true)
  document.removeEventListener('keydown', onPageInput, true)
  observer?.disconnect(); animator?.dispose(); animator = null
})
</script>

<template>
  <section class="dice-tray" :aria-label="label ?? 'Mesa de dados'" :aria-busy="phase === 'loading' || phase === 'rolling'">
    <div
      v-show="!unavailable" ref="host" :class="overlay ? ['dice-overlay', { covering, fading }] : 'dice-stage'"
    >
      <canvas ref="canvas" aria-hidden="true"></canvas>
      <button v-if="phase === 'rolling'" type="button" class="skip" @click="animator?.skip()">Pular animação</button>
      <p v-if="phase === 'loading'" class="stage-note">{{ overlay ? 'Preparando os dados…' : 'Preparando a mesa…' }}</p>
      <p v-else-if="phase === 'idle' && !ready && !overlay" class="stage-note" aria-hidden="true">Mesa de dados</p>
    </div>
    <p v-if="unavailable" class="help">Dados 3D indisponíveis neste navegador; o resultado é exibido em texto.</p>
    <ol v-if="record && phase === 'done'" class="dice-results" aria-label="Valores">
      <li v-for="(d, i) in ordered" :key="i">{{ d.result }}<span class="sr-only"> em {{ d.type }}</span></li>
    </ol>
    <p v-if="record && phase === 'done' && record.dice.length > 1" class="dice-total">Soma: <strong>{{ total }}</strong></p>
    <p class="sr-only" role="status" aria-live="polite">{{ announced }}</p>
    <p v-if="stats && phase === 'done'" class="help stats">{{ stats }}</p>
  </section>
</template>

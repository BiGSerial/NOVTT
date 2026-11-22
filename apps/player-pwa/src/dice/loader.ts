import type { DiceAnimator, Surface } from './stage'

/** Lazily loads the 3D stack (three.js ≈ 135 kB gzip, Rapier WebAssembly ≈ 1.2 MB gzip) only when dice are first shown. */
let modules: Promise<{ create: (canvas: HTMLCanvasElement, surface?: Surface) => DiceAnimator }> | null = null

export function loadDiceStage(): Promise<{ create: (canvas: HTMLCanvasElement, surface?: Surface) => DiceAnimator }> {
  // The WebAssembly module is instantiated when the Rapier chunk is imported (vite-plugin-wasm).
  modules ??= Promise.all([import('./stage'), import('@dimforge/rapier3d-deterministic')]).then(([stage, rapier]) => ({
    create: (canvas: HTMLCanvasElement, surface?: Surface) => new stage.DiceStage(canvas, rapier, surface),
  })).catch(error => { modules = null; throw error })
  return modules
}

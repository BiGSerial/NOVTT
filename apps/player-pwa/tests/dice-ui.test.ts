// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, type App } from 'vue'
import FreeDiceView from '../src/views/FreeDiceView.vue'
import type { RollAnimationRecord } from '../src/dice/record'
import type { loadDiceStage } from '../src/dice/loader'
import type { DiceAnimator } from '../src/dice/stage'
import { axeViolations } from './support'

let app: App | null = null
const flush = async () => { for (let i = 0; i < 6; i++) await new Promise(r => setTimeout(r, 0)) }

function fakeLoader(played: RollAnimationRecord[]) {
  const animator: DiceAnimator = {
    play: vi.fn(async (record: RollAnimationRecord) => { played.push(record); return { simulationMs: 5, animationS: 1.5, attempt: 0, order: [...record.dice.keys()].reverse() } }),
    skip: vi.fn(), resize: vi.fn(), dispose: vi.fn(),
  }
  return { loader: vi.fn(async () => ({ create: () => animator })), animator }
}

function mount(loader: typeof loadDiceStage): HTMLElement {
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h(FreeDiceView, { loader }) })
  app.mount(root)
  return root
}

afterEach(() => { app?.unmount(); app = null; document.body.replaceChildren(); localStorage.clear() })

describe('free dice with 3D tray', () => {
  it('offers d6–d20 and 1–6 dice, rolls, animates and announces the result in slot order', async () => {
    const played: RollAnimationRecord[] = []
    const { loader } = fakeLoader(played)
    const root = mount(loader)
    expect([...root.querySelectorAll('input[name=die-type]')].map(i => (i as HTMLInputElement).value)).toEqual(['d6', 'd8', 'd10', 'd12', 'd20'])
    expect(root.querySelectorAll('input[name=die-count]')).toHaveLength(6)
    ;(root.querySelector('input[name=die-count][value="3"]') as HTMLInputElement).click()
    await flush()
    root.querySelector<HTMLButtonElement>('.panel > button')!.click()
    await flush()

    expect(played).toHaveLength(1)
    const values = [...root.querySelectorAll('.dice-results li')].map(li => Number(li.firstChild!.textContent))
    expect(values).toEqual(played[0].dice.map(d => d.result).reverse())
    expect(root.querySelector('[role=status]')!.textContent).toContain('Resultado: 3d20')
    expect(root.querySelector('canvas')!.getAttribute('aria-hidden')).toBe('true')
    expect(await axeViolations(root)).toEqual([])
  })

  it('falls back to text when the 3D stack cannot load (e.g. no WebGL or offline)', async () => {
    const root = mount(vi.fn(async () => { throw new Error('no webgl') }) as unknown as typeof loadDiceStage)
    root.querySelector<HTMLButtonElement>('.panel > button')!.click()
    await flush()
    expect(root.textContent).toContain('Dados 3D indisponíveis')
    expect(root.querySelectorAll('.dice-results li')).toHaveLength(1)
    expect(root.querySelector('[role=status]')!.textContent).toMatch(/Resultado: 1d20: \d+/)
  })

  it('skin choice is kept in the record and persisted locally', async () => {
    const played: RollAnimationRecord[] = []
    const root = mount(fakeLoader(played).loader)
    const rubi = [...root.querySelectorAll<HTMLButtonElement>('.skin')].find(b => b.textContent?.includes('Rubi'))!
    rubi.click()
    await flush()
    expect(rubi.getAttribute('aria-checked')).toBe('true')
    root.querySelector<HTMLButtonElement>('.panel > button')!.click()
    await flush()
    expect(played[0].skin.id).toBe('rubi')
    expect(JSON.parse(localStorage.getItem('novtt.dice.skin')!).id).toBe('rubi')
  })
})

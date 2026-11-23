// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import Portrait from '../src/components/live/Portrait.vue'
import ClockDial from '../src/components/live/ClockDial.vue'
import { picture } from '../src/live/art'
import { oracle } from '../src/live/oracle'
import { TableState } from '../src/live/table'
import { ART_KEYS, type SessionEvent, type TableSnapshot } from '../src/live/types'

const SID = '00000000-0000-4000-8000-000000000002'
function snapshot(role: 'master' | 'player' = 'player'): TableSnapshot {
  return {
    contract: 'novtt.table-snapshot/1', role, me: { user_id: 'u-ana', name: 'Ana' },
    session: { id: SID, campaign_id: 'c', title: 'S', status: 'active', roteiro_id: null, initiative: {}, revealed_npcs: [], last_seq: 1, revision: 3, started_at: '2026-11-23T00:00:00Z', stage: null },
    members: [], characters: [{ id: 'lyra', name: 'Lyra', player_user_id: 'u-ana', controlled_by: 'u-ana', status: 'approved', preset_version_id: 'p', live: null, portrait_image_id: null }],
    npcs: [], requests: [], rolls: [], whispers: [], clues: [], suggestions: [], notes: [],
  }
}
const ev = (seq: number, type: string, payload: object): SessionEvent => ({ contract: 'novtt.session-event/1', session_id: SID, seq, type, occurred_at: '', payload: payload as Record<string, unknown> })
function mount(component: object, props: Record<string, unknown>): HTMLElement {
  const el = document.createElement('div')
  createApp({ render: () => h(component as never, props) }).mount(el)
  return el
}
const seq = (n: number) => { let i = 0; return () => [0.1, 0.5, 0.9, 0.3][i++ % 4] * n }

describe('narrative table events (SPEC-MVP-003)', () => {
  it('walks the plot and follows the compass the server chose', () => {
    const t = new TableState(snapshot('master'), vi.fn())
    const plot = { scenes: [{ id: 's1', title: 'Ponte', goal: 'Atravessar', status: 'done' as const }, { id: 's2', title: 'Estrada', goal: 'Chegar', status: 'current' as const }], current: 1 }
    t.receive(ev(2, 'plot.updated', { revision: 4, plot, compass: { scene: 'Estrada', objective: 'Chegar', next_clue: '', clocks: [], spotlight: {} } }))
    expect(t.s.session.plot?.current).toBe(1)
    expect(t.s.session.compass?.scene).toBe('Estrada')
    expect(t.s.session.revision).toBe(4)
  })

  it('shows a staged scene to players and tells the view', () => {
    const onStage = vi.fn()
    const t = new TableState(snapshot(), vi.fn(), { onStage })
    const stage = { title: 'A estrada', text: 'Lama e salgueiros.', art: 'road', image_id: null, shown_at: '2026-11-23T01:00:00Z' }
    t.receive(ev(2, 'stage.updated', { revision: 4, stage }))
    expect(t.s.session.stage?.title).toBe('A estrada')
    expect(onStage).toHaveBeenCalledWith(stage)
    t.receive(ev(3, 'stage.updated', { revision: 5, stage: null }))
    expect(t.s.session.stage).toBeNull()
  })

  it('updates a portrait for everyone without a reload', () => {
    const t = new TableState(snapshot(), vi.fn())
    t.receive(ev(2, 'portrait.updated', { kind: 'character', id: 'lyra', portrait_image_id: 'img-1' }))
    expect(t.s.characters[0].portrait_image_id).toBe('img-1')
  })
})

describe('original illustrations and improvisation aids', () => {
  it('draws every setting with finite paths', () => {
    for (const k of ART_KEYS) {
      const p = picture(k)
      expect(p.layers.length).toBeGreaterThan(2)
      for (const l of p.layers) expect(l.d).not.toMatch(/NaN|undefined|Infinity/)
    }
  })

  it('oracle answers come from its tables and are deterministic for a given random source', () => {
    expect(oracle.ask('likely', () => 0.99)).toBe('Sim, e…')
    expect(oracle.ask('unlikely', () => 0.01)).toBe('Não, e…')
    expect(oracle.name(seq(1))).toBe(oracle.name(seq(1)))
    expect(oracle.aspects(seq(1))).toHaveLength(3)
    expect(oracle.npc(seq(1)).name).toMatch(/\S+ \S+/)
  })
})

describe('portrait and clock components', () => {
  it('a portrait without an image draws initials and reads the health track', () => {
    const el = mount(Portrait, { name: 'Guardião da Ponte', live: { resources: [{ id: 'v', label: 'Vida', current: 3, max: 12 }], conditions: [], revision: 1 } })
    const root = el.querySelector('.portrait')!
    expect(root.textContent).toContain('GP')
    expect(root.getAttribute('aria-label')).toBe('Guardião da Ponte, Vida 3 de 12')
    expect(root.classList.contains('low')).toBe(true)
  })

  it('a clock fills up to the clicked segment and empties the last one on a second click', async () => {
    const onSet = vi.fn()
    const el = mount(ClockDial, { label: 'Cheia do rio', segments: 6, filled: 2, editable: true, onSet })
    const segs = el.querySelectorAll('.dial-seg')
    expect(segs).toHaveLength(6)
    segs[3].dispatchEvent(new MouseEvent('click'))
    segs[1].dispatchEvent(new MouseEvent('click'))
    await nextTick()
    expect(onSet.mock.calls).toEqual([[4], [1]])
  })
})

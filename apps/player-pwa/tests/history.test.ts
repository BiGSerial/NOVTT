import { describe, expect, it } from 'vitest'
import { buildHistory } from '../src/live/history'
import type { SessionEvent } from '../src/live/types'

const ev = (seq: number, type: string, payload: unknown): SessionEvent =>
  ({ contract: 'novtt.session-event/1', session_id: 's', seq, type, occurred_at: `2026-11-23T20:${String(seq).padStart(2, '0')}:00.000Z`, payload } as SessionEvent)
const ctx = { name: (id: string) => ({ c1: 'Lyra' } as Record<string, string>)[id] ?? '—', player: (u?: string | null) => ({ u1: 'Ana', m: 'Mestre' } as Record<string, string>)[u ?? ''] ?? 'alguém' }
const live = (current: number, conditions: string[], items: { label: string; qty: number }[] = []) =>
  ({ resources: [{ id: 'vit', label: 'Vida', current, max: 7 }], conditions, items: items.map((i, n) => ({ id: `i${n}`, ...i })), revision: current })

describe('session history', () => {
  it('tells vitals, conditions and items as differences, in stream order', () => {
    const lines = buildHistory([
      ev(2, 'live.updated', { subject: { kind: 'character', id: 'c1' }, live: live(5, ['Envenenado'], [{ label: 'Flechas', qty: 10 }]) }),
      ev(1, 'live.updated', { subject: { kind: 'character', id: 'c1' }, live: live(7, [], [{ label: 'Flechas', qty: 12 }]) }),
    ], ctx)
    expect(lines.map(l => l.text)).toEqual(['Lyra: estado atualizado', 'Lyra: Vida 7→5/7 · + Envenenado · −2 Flechas'])
    expect(lines.every(l => l.kind === 'state')).toBe(true)
  })

  it('names who did what: player requests, whispers, rolls with their outcome', () => {
    const lines = buildHistory([
      ev(1, 'suggestion.created', { id: 'sg', campaign_character_id: 'c1', character_name: 'Lyra', player_user_id: 'u1', paths: ['/a'], note: 'Escalar o muro', status: 'pending' }),
      ev(2, 'whisper.received', { id: 'w', sender_user_id: 'u1', recipient_user_id: 'm', kind: 'message', body: 'Posso ir sozinha?', created_at: '' }),
      ev(3, 'roll.resolved', { id: 'r', subject: { kind: 'character', id: 'c1', name: 'Lyra' }, roller_user_id: 'u1', label: 'Agilidade', results: [6, 2, 5], total: 13, successes: 2, succeeded: true, dice: { reading: 'successes' } }),
    ], ctx)
    expect(lines[0]).toMatchObject({ kind: 'player', text: 'Ana pediu para rolar com Lyra', detail: 'Escalar o muro' })
    expect(lines[1]).toMatchObject({ kind: 'player', text: 'Ana → Mestre', detail: 'Posso ir sozinha?' })
    expect(lines[2]).toMatchObject({ kind: 'roll', tone: 'win', text: 'Lyra (Ana) · Agilidade' })
    expect(lines[2]!.detail).toContain('2 sucessos · Sucesso')
  })

  it('records scene changes once and skips workspace detail', () => {
    const plot = (current: number) => ({ plot: { current, scenes: [{ id: 'a', title: 'Ponte' }, { id: 'b', title: 'Cabana' }] }, revision: current })
    const lines = buildHistory([
      ev(1, 'plot.updated', plot(0)), ev(2, 'plot.updated', plot(0)), ev(3, 'compass.updated', {}), ev(4, 'plot.updated', plot(1)),
      ev(5, 'atmosphere.updated', { hour: 22.5, weather: 'snow', fog: 0.4 }),
    ], ctx)
    expect(lines.map(l => l.text)).toEqual(['Cena: Ponte', 'Cena: Cabana', 'Clima: neve com névoa · 22:30'])
  })
})

// The session's history for the master: every event of the stream as one readable line, in order.
// Pure (events in, lines out) so it is tested without a server. Vitals/conditions/items changes are
// told as differences from the previous state of the same character seen in the stream.
import { outcomeText } from './dice'
import type { Atmosphere } from './atmosphere'
import type { Clue, LiveState, OfficialRoll, Plot, RevealedNpc, RollRequest, SessionEvent, Stage, Suggestion, Whisper } from './types'

export type HistoryKind = 'roll' | 'request' | 'player' | 'state' | 'story' | 'table'
export interface HistoryLine { seq: number; at: string; kind: HistoryKind; icon: string; text: string; detail?: string; tone?: 'win' | 'lose' }
export interface HistoryContext {
  /** Character / NPC names by id. */
  name: (id: string) => string
  /** A member's display name by user id. */
  player: (userId: string | null | undefined) => string
}

const WEATHER: Record<string, string> = { clear: 'céu limpo', clouds: 'nublado', rain: 'chuva', storm: 'tempestade', fog: 'névoa', snow: 'neve' }
const hh = (h: number) => `${String(Math.floor(h)).padStart(2, '0')}:${String(Math.round((h % 1) * 60)).padStart(2, '0')}`

function liveDiff(before: LiveState | undefined, after: LiveState): string {
  if (!before) {
    // No earlier state in the stream: tell only what stands out (not full, conditions).
    const res = after.resources.filter(r => r.current !== r.max).map(r => `${r.label} ${r.current}/${r.max}`).join(' · ')
    return [res, after.conditions.join(', ')].filter(Boolean).join(' · ') || 'estado atualizado'
  }
  const parts: string[] = []
  for (const r of after.resources) {
    const b = before.resources.find(x => x.id === r.id)
    if (!b || b.current !== r.current || b.max !== r.max) parts.push(`${r.label} ${b ? `${b.current}→` : ''}${r.current}/${r.max}`)
  }
  for (const c of after.conditions) if (!before.conditions.includes(c)) parts.push(`+ ${c}`)
  for (const c of before.conditions) if (!after.conditions.includes(c)) parts.push(`− ${c}`)
  const qty = (l?: LiveState['items']) => new Map((l ?? []).map(i => [i.label, i.qty]))
  const bi = qty(before.items)
  const ai = qty(after.items)
  for (const [label, n] of ai) { const d = n - (bi.get(label) ?? 0); if (d) parts.push(`${d > 0 ? '+' : '−'}${Math.abs(d)} ${label}`) }
  for (const [label, n] of bi) if (!ai.has(label)) parts.push(`−${n} ${label}`)
  return parts.join(' · ')
}

export function buildHistory(events: SessionEvent[], ctx: HistoryContext): HistoryLine[] {
  const lines: HistoryLine[] = []
  const lives = new Map<string, LiveState>()
  let scene: string | null = null
  const sorted = [...events].sort((a, b) => a.seq - b.seq)
  for (const e of sorted) {
    const at = e.occurred_at
    const add = (kind: HistoryKind, icon: string, text: string, extra: Partial<HistoryLine> = {}) => lines.push({ seq: e.seq, at, kind, icon, text, ...extra })
    const p = e.payload as Record<string, unknown>
    switch (e.type) {
      case 'roll.requested': {
        const r = p as unknown as RollRequest
        add('request', '🎯', `Mestre pediu ${r.label} a ${r.subject.name}`, { detail: r.secret ? 'entre vocês' : undefined })
        break
      }
      case 'roll.resolved':
      case 'roll.revealed': {
        const r = p as unknown as OfficialRoll
        const verdict = r.succeeded === true ? 'win' : r.succeeded === false ? 'lose' : undefined
        const who = r.subject.kind === 'npc' ? r.subject.name : `${r.subject.name} (${ctx.player(r.roller_user_id)})`
        add('roll', e.type === 'roll.revealed' ? '👁' : '🎲', `${e.type === 'roll.revealed' ? 'Revelada: ' : ''}${who} · ${r.label}`, { detail: `${outcomeText(r)} · [${r.results.join(', ')}]`, tone: verdict })
        break
      }
      case 'suggestion.created': {
        const sg = p as unknown as Suggestion
        add('player', '✋', `${ctx.player(sg.player_user_id)} pediu para rolar com ${sg.character_name}`, { detail: sg.note ?? undefined })
        break
      }
      case 'suggestion.resolved':
        if (p.status === 'dismissed') add('request', '✋', 'Pedido de jogador dispensado')
        break
      case 'whisper.received': {
        const w = p as unknown as Whisper
        add('player', '🤫', `${ctx.player(w.sender_user_id)} → ${ctx.player(w.recipient_user_id)}`, { detail: w.body })
        break
      }
      case 'clue.revealed': {
        const c = p as unknown as Clue
        add('story', '🗝', `Pista ${c.revealed_to === 'table' ? 'revelada à mesa' : `para ${ctx.player(c.revealed_user_id)}`}: ${c.title}`)
        break
      }
      case 'live.updated': {
        const { subject, live } = p as { subject: { id: string }; live: LiveState }
        const text = liveDiff(lives.get(subject.id), live)
        lives.set(subject.id, live)
        if (text) add('state', '❤', `${ctx.name(subject.id)}: ${text}`)
        break
      }
      case 'plot.updated': {
        const plot = p.plot as Plot
        const cur = plot.current === null ? null : plot.scenes[plot.current]?.title ?? null
        if (cur && cur !== scene) add('story', '📜', `Cena: ${cur}`)
        scene = cur
        break
      }
      case 'stage.updated': {
        const st = p.stage as Stage | null
        add('story', '🖼', st ? `Mostrou aos jogadores: ${st.title}` : 'Recolheu a cena da tela')
        break
      }
      case 'npc.revealed':
        add('story', '🎭', `Apresentou ${(p as unknown as RevealedNpc).name} à mesa`)
        break
      case 'atmosphere.updated': {
        const a = p as unknown as Atmosphere
        add('table', '☁', `Clima: ${WEATHER[a.weather] ?? a.weather}${(a.fog ?? 0) > 0.05 ? ' com névoa' : ''} · ${hh(a.hour)}`)
        break
      }
      case 'session.status':
        add('table', '⏸', p.status === 'paused' ? 'Sessão pausada' : p.status === 'ended' ? 'Sessão encerrada' : 'Sessão retomada')
        break
      case 'places.updated': {
        const places = (p.places as { name: string; character_ids: string[] }[] | undefined) ?? []
        add('story', '🧭', places.length ? `Locais: ${places.map(x => `${x.name} (${x.character_ids.map(ctx.name).join(', ') || 'ninguém'})`).join(' · ')}` : 'O grupo está junto')
        break
      }
      case 'item_offer.created':
        add('player', '🎁', `${ctx.name(String(p.from_character_id))} oferece ${p.qty} ${p.item_label} a ${ctx.name(String(p.to_character_id))}`)
        break
      case 'item_offer.resolved': {
        const verb = p.status === 'accepted' ? 'aceitou' : p.status === 'undone' ? 'teve desfeita pelo mestre a troca de' : 'recusou'
        add('player', '🎁', p.status === 'undone' ? `Mestre desfez: ${p.qty} ${p.item_label} voltou para ${ctx.name(String(p.from_character_id))}` : `${ctx.name(String(p.to_character_id))} ${verb} ${p.qty} ${p.item_label}`)
        break
      }
      case 'portrait.updated':
        if (p.kind === 'character') add('player', '🖼', `${ctx.name(String(p.id))} ganhou um novo retrato`)
        break
      default:
        break // compass, initiative, notes and clocks are workspace detail, not history
    }
  }
  return lines
}

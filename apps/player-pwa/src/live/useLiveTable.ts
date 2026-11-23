import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { ApiError } from '../api/client'
import type { RollAnimationRecord } from '../dice/record'
import { loadSkin } from '../dice/skins'
import { campaigns, session as sessionApi } from './api'
import { officialRecord } from './dice'
import { connectRealtime, type LinkState, type Realtime } from './realtime'
import { TableState } from './table'
import type { Clue, OfficialRoll, SessionEvent, Whisper } from './types'

/**
 * Wires one campaign's live table: realtime subscriptions first (events are buffered), then the
 * snapshot, then buffered + live events; reconnects trigger a catch-up by seq. Official results
 * are queued for the 3D dice overlay and their result card (SPEC-MVP-002 D4).
 */
export function useLiveTable(campaignId: string, opts: { connect?: () => Promise<Realtime> } = {}) {
  const state = shallowRef<TableState | null>(null)
  const phase = ref<'loading' | 'waiting' | 'ready' | 'error'>('loading')
  const link = ref<LinkState>('connecting')
  const error = ref('')
  const dice = shallowRef<RollAnimationRecord | null>(null)
  const card = shallowRef<OfficialRoll | null>(null)
  const toasts = ref<{ id: string; text: string; kind: 'whisper' | 'clue' | 'info' }[]>([])
  const queue: OfficialRoll[] = []
  let realtime: Realtime | null = null
  const unsubs: (() => void)[] = []
  let buffer: SessionEvent[] = []
  let disposed = false
  let wasLive = false
  let cardTimer: ReturnType<typeof setTimeout> | undefined

  function toast(text: string, kind: 'whisper' | 'clue' | 'info' = 'info'): void {
    const id = crypto.randomUUID()
    toasts.value = [...toasts.value.slice(-3), { id, text, kind }]
    setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 6000)
  }

  function nextRoll(): void {
    if (dice.value || card.value || pendingCard) return
    const roll = queue.shift()
    if (!roll) return
    const record = officialRecord(roll, loadSkin())
    card.value = null
    if (record) {
      dice.value = record
      pendingCard = roll
    } else {
      showCard(roll)
    }
  }
  let pendingCard: OfficialRoll | null = null

  /**
   * Called by the dice overlay when the dice settled: the result comes to the front while the
   * dice stay on the table under it. Both leave together when the card is dismissed (tap, Esc)
   * or after CARD_MS; a queued roll then takes the stage.
   */
  function settled(): void {
    if (pendingCard) showCard(pendingCard)
    pendingCard = null
  }

  const CARD_MS = 14000
  function showCard(roll: OfficialRoll): void {
    card.value = roll
    clearTimeout(cardTimer)
    cardTimer = setTimeout(dismissCard, queue.length ? CARD_MS / 2 : CARD_MS)
  }

  function dismissCard(): void {
    clearTimeout(cardTimer)
    card.value = null
    dice.value = null
    setTimeout(nextRoll, 500)
  }

  function enqueue(roll: OfficialRoll): void {
    if (queue.length > 4) queue.shift()
    queue.push(roll)
    nextRoll()
  }

  function onEvent(e: SessionEvent): void {
    if (e.type === 'session.status' && (!state.value || state.value.s.session.id !== e.session_id)) {
      // A new session in this campaign (or the first one): reload.
      void load()
      return
    }
    if (!state.value) buffer.push(e)
    else state.value.receive(e)
  }

  async function load(): Promise<void> {
    try {
      const current = await campaigns.current(campaignId)
      if (disposed) return
      if (!current.session) {
        state.value = null
        phase.value = 'waiting'
        return
      }
      const snap = await sessionApi.snapshot(current.session.id)
      if (disposed) return
      const t = new TableState(snap, after => sessionApi.events(snap.session.id, after), {
        onRoll: roll => enqueue(roll),
        onWhisper: (w: Whisper) => { if (w.sender_user_id !== snap.me.user_id) toast(w.kind === 'clue' ? 'Uma pista chegou só para você' : 'Novo sussurro', 'whisper') },
        onClue: (c: Clue) => toast(`Pista revelada: ${c.title}`, 'clue'),
        onStage: st => { if (st && snap.role !== 'master') toast(`Nova cena: ${st.title}`, 'clue') },
      })
      state.value = t
      const pending = buffer
      buffer = []
      pending.forEach(e => t.receive(e))
      if (snap.role === 'master' && !unsubs.length) await subscribe(true, snap.me.user_id)
      phase.value = snap.session.status === 'ended' ? 'waiting' : 'ready'
    } catch (e) {
      phase.value = 'error'
      error.value = e instanceof ApiError ? e.code : 'network.error'
    }
  }

  let subscribedMaster = false
  async function subscribe(asMaster: boolean, userId: string): Promise<void> {
    if (!realtime) return
    if (asMaster && !subscribedMaster) {
      subscribedMaster = true
      unsubs.push(realtime.subscribe(`campaign.${campaignId}.master`, onEvent))
    }
    void userId
  }

  async function start(meId: string, role: 'master' | 'player' | undefined): Promise<void> {
    try {
      realtime = await (opts.connect ?? connectRealtime)()
      unsubs.push(realtime.onState(s => {
        link.value = s
        if (s === 'live' && wasLive) void state.value?.catchUp()
        if (s === 'live') wasLive = true
      }))
      unsubs.push(realtime.subscribe(`campaign.${campaignId}`, onEvent))
      unsubs.push(realtime.subscribe(`user.${meId}`, onEvent))
      if (role === 'master') {
        subscribedMaster = true
        unsubs.push(realtime.subscribe(`campaign.${campaignId}.master`, onEvent))
      }
    } catch {
      // Without realtime the table still works by refreshing; say so.
      link.value = 'offline'
    }
    await load()
  }

  // Coming back to the tab: make sure nothing was missed while the device slept.
  const onVisible = () => { if (document.visibilityState === 'visible') void state.value?.catchUp() }
  document.addEventListener('visibilitychange', onVisible)

  onBeforeUnmount(() => {
    disposed = true
    clearTimeout(cardTimer)
    document.removeEventListener('visibilitychange', onVisible)
    unsubs.forEach(u => u())
    realtime?.disconnect()
  })

  return { state, phase, link, error, dice, card, toasts, start, reload: load, settled, dismissCard, enqueue, toast, realtime: () => realtime }
}

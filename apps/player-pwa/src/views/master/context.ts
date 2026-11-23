import { computed, inject, provide, reactive, ref, watch, type ComputedRef, type InjectionKey, type Ref } from 'vue'
import { ApiError } from '../../api/client'
import { images, rolls, session as sessionApi, table as tableApi, type RequestInput } from '../../live/api'
import { plain } from '../../live/sheet'
import type { TableState } from '../../live/table'
import type { useLiveTable } from '../../live/useLiveTable'
import { useSheets } from '../../live/useSheets'
import type {
  Campaign, Clock, Compass, DiceChoice, Item, LiveState, OfficialRoll, Plot, PlotScene, ResourceTrack, RollRequest, Stage, Suggestion, TableSnapshot,
} from '../../live/types'

export type SubjectKind = 'character' | 'npc'
export interface Subject { kind: SubjectKind; id: string }
export interface CastEntry {
  kind: SubjectKind
  id: string
  name: string
  sub: string
  portrait: string | null
  live: LiveState | null
  preset: string
  playerUserId: string | null
  concept: string | null
}
export interface ComposerInput { paths: string[]; modifier: number; dice: DiceChoice; attempts: number; secret: boolean; allows_choice: boolean; label: string }

/**
 * Shared state and commands of the master's console. Server data lives in TableState (events);
 * selection, "in scene" and the open tab are ephemeral workspace state of this window only.
 */
export function createMasterContext(table: ReturnType<typeof useLiveTable>, campaign: Campaign) {
  const t = computed(() => table.state.value as TableState)
  const s = computed(() => t.value.s as TableSnapshot)
  const sheets = useSheets(campaign.id)
  const busy = ref(false)

  // ---------- Cast & selection ----------
  const playerName = (id: string | null) => s.value.members.find(m => m.user_id === id)?.name ?? ''
  const cast = computed<CastEntry[]>(() => [
    ...s.value.characters.map(c => ({ kind: 'character' as const, id: c.id, name: c.name, sub: playerName(c.player_user_id), portrait: c.portrait_image_id ?? null, live: c.live, preset: c.preset_version_id, playerUserId: c.controlled_by, concept: c.concept ?? null })),
    ...s.value.npcs.map(n => ({ kind: 'npc' as const, id: n.id, name: n.name, sub: 'PdM', portrait: n.portrait_image_id ?? null, live: n.live, preset: n.preset_version_id, playerUserId: null, concept: null })),
  ])
  const byId = (id: string) => cast.value.find(c => c.id === id) ?? null
  const selection = ref<Subject[]>([])
  const focus = computed(() => { const last = selection.value[selection.value.length - 1]; return last ? byId(last.id) : null })
  const selected = (id: string) => selection.value.some(x => x.id === id)

  async function loadSheet(e: CastEntry): Promise<void> {
    try { await (e.kind === 'character' ? sheets.character(e.id, e.preset) : sheets.npc(e.id, e.preset)) } catch { table.toast('Não foi possível abrir a ficha.') }
  }
  /** Plain click: only this one. Additive (Ctrl/⌘/Shift or the checkbox): toggles it in the group. */
  function select(kind: SubjectKind, id: string, additive = false): void {
    if (!additive && focus.value?.id !== id) { paths.value = []; suggestionId.value = null }
    if (additive) selection.value = selected(id) ? selection.value.filter(x => x.id !== id) : [...selection.value, { kind, id }]
    else selection.value = [{ kind, id }]
    const e = byId(id)
    if (e) void loadSheet(e)
    if (!additive) table.realtime()?.whisper(`workspace.${s.value.me.user_id}`, 'select', { kind, id })
  }
  function selectMany(list: Subject[]): void {
    selection.value = list
    list.forEach(x => { const e = byId(x.id); if (e) void loadSheet(e) })
  }
  const clearSelection = () => { selection.value = []; paths.value = [] }

  // The combination being composed (attribute + skill paths) and the suggestion it came from.
  const paths = ref<string[]>([])
  const suggestionId = ref<string | null>(null)
  const togglePath = (p: string) => { paths.value = paths.value.includes(p) ? paths.value.filter(x => x !== p) : [...paths.value, p] }
  function fromSuggestion(sg: Suggestion): void {
    select('character', sg.campaign_character_id)
    paths.value = [...sg.paths]
    suggestionId.value = sg.id
  }

  // "Em cena": which NPCs are on stage right now (workspace state, per session).
  const sceneKey = () => `novtt.inscene.${s.value.session.id}`
  const inScene = ref<string[]>(readScene())
  function readScene(): string[] {
    try { return JSON.parse(sessionStorage.getItem(`novtt.inscene.${table.state.value?.s.session.id}`) ?? '[]') } catch { return [] }
  }
  watch(inScene, v => { try { sessionStorage.setItem(sceneKey(), JSON.stringify(v)) } catch { /* workspace only */ } }, { deep: true })
  const toggleInScene = (id: string) => { inScene.value = inScene.value.includes(id) ? inScene.value.filter(x => x !== id) : [...inScene.value, id] }
  function bringSceneNpcs(scene: PlotScene | null): void {
    if (!scene?.npcs?.length) return
    const names = scene.npcs.map(n => n.toLowerCase())
    inScene.value = s.value.npcs.filter(n => names.some(x => n.name.toLowerCase().includes(x) || x.includes(n.name.toLowerCase()))).map(n => n.id)
  }

  // ---------- Errors ----------
  function fail(e: unknown, fallback = 'Não deu certo. Tente de novo.'): void {
    const code = e instanceof ApiError ? e.code : ''
    const known: Record<string, string> = {
      'session.revision_mismatch': 'Outra janela mudou a sessão; atualizei para a versão mais nova.',
      'live_state.revision_mismatch': 'Essa ficha mudou em outra janela; tente de novo.',
      'session.paused': 'A sessão está pausada.',
      'roll.component_invalid': 'Essa combinação não existe na ficha.',
      'whisper.recipient_invalid': 'Escolha um jogador para sussurrar.',
      'image.type_unsupported': 'Use uma imagem JPG, PNG ou WebP.',
      'image.too_large': 'Imagem grande demais (até 4 MB e 6000 px).',
      'image.limit': 'A campanha atingiu o limite de imagens.',
      'network.error': 'Sem conexão. Tente de novo.',
    }
    table.toast(known[code] ?? fallback)
    if (code.endsWith('revision_mismatch')) void t.value.catchUp()
  }

  // ---------- Rolls ----------
  async function request(targets: Subject[], input: ComposerInput, suggestionId: string | null = null): Promise<void> {
    busy.value = true
    let sent = 0
    try {
      for (const target of targets) {
        if (target.kind === 'character') {
          const body: RequestInput = { ...input, campaign_character_id: target.id, suggestion_id: targets.length === 1 ? suggestionId : null }
          t.value.local.request(await rolls.request(s.value.session.id, body))
        } else {
          await rolls.npc(s.value.session.id, { campaign_npc_id: target.id, paths: input.paths, modifier: input.modifier, dice: input.dice, label: input.label, secret: input.secret })
        }
        sent++
      }
      const pcs = targets.filter(x => x.kind === 'character').length
      if (pcs) table.toast(pcs === 1 ? `Pedido enviado a ${byId(targets.find(x => x.kind === 'character')!.id)?.name}` : `Pedido enviado a ${pcs} jogadores`)
    } catch (e) {
      fail(e)
      if (sent) table.toast(`${sent} de ${targets.length} pedidos enviados.`)
    } finally {
      busy.value = false
    }
  }
  const dismiss = async (sg: Suggestion) => { try { await rolls.dismiss(sg.id) } catch (e) { fail(e) } }
  const cancel = async (r: RollRequest) => { try { t.value.local.request(await rolls.cancel(r.id)) } catch (e) { fail(e) } }
  const grant = async (r: RollRequest) => { try { t.value.local.request(await rolls.grant(r.id)) } catch (e) { fail(e) } }
  const reveal = async (roll: OfficialRoll) => { try { await rolls.reveal(roll.id); table.toast('Rolagem revelada à mesa') } catch (e) { fail(e) } }
  const pendingFor = (id: string) => s.value.requests.filter(r => r.subject.id === id)

  // ---------- Live state (vitals, conditions, items) ----------
  async function saveLive(kind: SubjectKind, id: string, patch: { resources?: ResourceTrack[]; conditions?: string[]; items?: Item[] }): Promise<boolean> {
    const target = (kind === 'character' ? s.value.characters : s.value.npcs).find(x => x.id === id)
    if (!target) return false
    const live = target.live
    try {
      const r = await tableApi.live(campaign.id, kind, id, live?.revision ?? 0, patch.resources ?? live?.resources ?? [], patch.conditions ?? live?.conditions ?? [], patch.items)
      t.value.local.live(kind, id, r.live)
      return true
    } catch (e) { fail(e); return false }
  }
  /** Damage (negative) or healing on the first track of every selected subject. */
  async function adjustMany(list: Subject[], delta: number): Promise<void> {
    busy.value = true
    try {
      for (const x of list) {
        const e = byId(x.id)
        const track = e?.live?.resources?.[0]
        if (!e || !track) continue
        await saveLive(x.kind, x.id, { resources: e.live!.resources.map((r, i) => (i === 0 ? { ...r, current: Math.max(-99, Math.min(r.max, r.current + delta)) } : r)) })
      }
    } finally { busy.value = false }
  }
  async function conditionMany(list: Subject[], condition: string, on: boolean): Promise<void> {
    busy.value = true
    try {
      for (const x of list) {
        const e = byId(x.id)
        if (!e) continue
        const has = e.live?.conditions?.includes(condition) ?? false
        if (has === on) continue
        await saveLive(x.kind, x.id, { conditions: on ? [...(e.live?.conditions ?? []), condition] : (e.live?.conditions ?? []).filter(c => c !== condition) })
      }
    } finally { busy.value = false }
  }

  // ---------- Compass, plot & stage ----------
  const compass = reactive<Compass>({ scene: '', objective: '', next_clue: '', clocks: [], spotlight: {} })
  watch(() => s.value.session.compass, c => { if (c) Object.assign(compass, plain({ ...c, spotlight: { ...(c.spotlight ?? {}) } })) }, { immediate: true, deep: true })
  async function saveCompass(): Promise<void> {
    try { t.value.local.session(await sessionApi.compass(s.value.session.id, s.value.session.revision, { ...plain(compass), spotlight: { ...compass.spotlight } })) } catch (e) { fail(e) }
  }
  function setClock(c: Clock, filled: number): void { c.filled = filled; void saveCompass() }
  function addClock(label = 'Nova ameaça', segments = 4): void { compass.clocks.push({ id: `c${Date.now().toString(36)}`, label, segments, filled: 0 }); void saveCompass() }
  function removeClock(c: Clock): void { compass.clocks = compass.clocks.filter(x => x.id !== c.id); void saveCompass() }
  function spotlight(id: string): void { compass.spotlight = { ...compass.spotlight, [id]: (compass.spotlight[id] ?? 0) + 1 }; void saveCompass() }
  const spotlightLow = computed(() => {
    const counts = s.value.characters.map(c => compass.spotlight[c.id] ?? 0)
    const min = Math.min(...counts)
    return new Set(s.value.characters.filter(c => (compass.spotlight[c.id] ?? 0) === min && Math.max(...counts) > min).map(c => c.id))
  })

  const plot = computed<Plot>(() => s.value.session.plot ?? { scenes: [], current: null })
  const currentScene = computed<PlotScene | null>(() => (plot.value.current === null ? null : plot.value.scenes[plot.value.current] ?? null))
  async function savePlot(next: Plot): Promise<boolean> {
    try { t.value.local.session(await sessionApi.plot(s.value.session.id, s.value.session.revision, plain(next))); return true } catch (e) { fail(e); return false }
  }
  /** Walks the story: the compass follows the new scene; its NPCs come on stage in this window. */
  async function goToScene(index: number): Promise<void> {
    if (index < 0 || index >= plot.value.scenes.length) return
    if (await savePlot({ ...plot.value, current: index })) {
      bringSceneNpcs(plot.value.scenes[index] ?? null)
      table.toast(`Cena: ${plot.value.scenes[index]?.title}`)
    }
  }
  async function addScene(scene: Omit<PlotScene, 'id' | 'status'>, makeCurrent = false): Promise<void> {
    const scenes = [...plot.value.scenes, { ...scene, id: `s${Date.now().toString(36)}`, status: 'pending' as const }]
    if (await savePlot({ scenes, current: makeCurrent ? scenes.length - 1 : plot.value.current ?? 0 })) {
      if (makeCurrent) bringSceneNpcs(scenes[scenes.length - 1])
    }
  }
  const stage = computed<Stage | null>(() => s.value.session.stage ?? null)
  async function showStage(next: Omit<Stage, 'shown_at'> | null): Promise<void> {
    try {
      t.value.local.session(await sessionApi.stage(s.value.session.id, s.value.session.revision, next))
      table.toast(next ? 'Cena mostrada aos jogadores' : 'Tela dos jogadores recolhida')
    } catch (e) { fail(e) }
  }
  const showScene = (sc: PlotScene) => showStage({ title: sc.title, text: sc.read_aloud ?? '', art: sc.art ?? null, image_id: sc.image_id ?? null })

  // ---------- Initiative ----------
  const initiative = computed(() => s.value.session.initiative?.entries ?? [])
  async function saveInitiative(entries: Subject[], turn: number, round: number): Promise<void> {
    try { t.value.local.session(await sessionApi.initiative(s.value.session.id, s.value.session.revision, entries, turn, round)) } catch (e) { fail(e) }
  }
  const initFrom = (list: Subject[]) => saveInitiative(list, 0, 1)
  function initMove(i: number, d: number): void {
    const e: Subject[] = initiative.value.map(x => ({ kind: x.kind, id: x.id }))
    const j = i + d
    if (j < 0 || j >= e.length) return
    ;[e[i], e[j]] = [e[j], e[i]]
    void saveInitiative(e, s.value.session.initiative.turn ?? 0, s.value.session.initiative.round ?? 1)
  }
  const initRemove = (i: number) => saveInitiative(initiative.value.map(x => ({ kind: x.kind, id: x.id })).filter((_, k) => k !== i), 0, s.value.session.initiative.round ?? 1)
  function nextTurn(): void {
    const n = initiative.value.length
    if (!n) return
    const turn = (s.value.session.initiative.turn ?? 0) + 1
    void saveInitiative(initiative.value.map(x => ({ kind: x.kind, id: x.id })), turn % n, (s.value.session.initiative.round ?? 1) + (turn >= n ? 1 : 0))
  }
  const endInitiative = () => saveInitiative([], 0, 1)

  // ---------- Whispers, clues, NPC reveal ----------
  const players = computed(() => s.value.members.filter(m => m.role === 'player'))
  async function whisper(userId: string, body: string): Promise<boolean> {
    if (!body.trim()) return false
    try { t.value.local.whisper(await tableApi.whisper(campaign.id, userId, body.trim())); return true } catch (e) { fail(e); return false }
  }
  async function revealClue(id: string, to: 'table' | 'user', userId?: string): Promise<void> {
    try {
      t.value.local.clue(await tableApi.revealClue(id, to, userId))
      table.toast(to === 'table' ? 'Pista revelada à mesa' : `Pista sussurrada a ${playerName(userId ?? null)}`)
    } catch (e) { fail(e) }
  }
  async function addClue(title: string, body: string, scene: string | null, imageId: string | null = null): Promise<boolean> {
    try { t.value.local.clue(await tableApi.createClue(s.value.session.id, title, body || title, scene, imageId)); return true } catch (e) { fail(e); return false }
  }
  async function revealNpc(id: string, summary: string): Promise<void> {
    try { await tableApi.revealNpc(s.value.session.id, id, summary.slice(0, 300)); table.toast(`${byId(id)?.name} apresentado à mesa`) } catch (e) { fail(e) }
  }
  async function note(body: string, tags: string[]): Promise<void> {
    try { t.value.local.note(await tableApi.createNote(campaign.id, body, tags)); table.toast('Anotado') } catch (e) { fail(e) }
  }

  // ---------- Images ----------
  async function upload(file: File, kind: 'portrait' | 'scene' | 'handout'): Promise<string | null> {
    try { return (await images.upload(campaign.id, file, kind)).id } catch (e) { fail(e, 'Não foi possível enviar a imagem.'); return null }
  }
  async function setPortrait(kind: SubjectKind, id: string, file: File): Promise<void> {
    const imageId = await upload(file, 'portrait')
    if (!imageId) return
    try { await images.setPortrait(campaign.id, kind, id, imageId); t.value.local.portrait(kind, id, imageId) } catch (e) { fail(e) }
  }

  return {
    table, campaign, t, s, sheets, busy, cast, byId, selection, focus, selected, select, selectMany, clearSelection,
    inScene, toggleInScene, paths, togglePath, suggestionId, fromSuggestion, playerName, fail, request, dismiss, cancel, grant, reveal, pendingFor,
    saveLive, adjustMany, conditionMany, compass, saveCompass, setClock, addClock, removeClock, spotlight, spotlightLow,
    plot, currentScene, savePlot, goToScene, addScene, stage, showStage, showScene,
    initiative, initFrom, initMove, initRemove, nextTurn, endInitiative,
    players, whisper, revealClue, addClue, revealNpc, note, upload, setPortrait,
  }
}

export type MasterContext = ReturnType<typeof createMasterContext>
const KEY: InjectionKey<MasterContext> = Symbol('novtt.master')
export const provideMaster = (ctx: MasterContext) => provide(KEY, ctx)
export function useMaster(): MasterContext {
  const ctx = inject(KEY)
  if (!ctx) throw new Error('useMaster outside the master table')
  return ctx
}
export type { ComputedRef, Ref }

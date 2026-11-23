import { reactive } from 'vue'
import { sheets } from './api'
import { presets } from './campaign'
import { sheetSections, type SheetField, type SheetSection } from './sheet'

/**
 * Loads and caches sheets for the live table: the server already projected each sheet for the
 * viewer (players get their own; masters get all, NPCs included). Reload after a known change.
 */
export function useSheets(campaignId: string) {
  const byId = reactive(new Map<string, { sections: SheetSection[]; numeric: SheetField[]; raw: Record<string, unknown> }>())
  const loading = new Map<string, Promise<void>>()
  let npcSheets: Promise<Map<string, Record<string, unknown>>> | null = null

  async function build(id: string, presetVersionId: string, raw: Record<string, unknown>): Promise<void> {
    const [ui, schema] = await Promise.all([
      sheets.layout(presetVersionId),
      presets.version(presetVersionId).then(v => v.sheet_schema).catch(() => undefined),
    ])
    const sections = sheetSections(ui, raw, schema)
    byId.set(id, { sections, numeric: sections.filter(s => s.numeric).flatMap(s => s.fields), raw })
  }

  function character(id: string, presetVersionId: string, force = false): Promise<void> {
    if (!force && loading.has(id)) return loading.get(id)!
    const p = sheets.character(id).then(c => build(id, presetVersionId, c.sheet as Record<string, unknown>))
    p.catch(() => loading.delete(id))
    loading.set(id, p)
    return p
  }

  function npc(id: string, presetVersionId: string, force = false): Promise<void> {
    if (!force && loading.has(id)) return loading.get(id)!
    if (force || !npcSheets) npcSheets = sheets.npcs(campaignId).then(r => new Map(r.data.map(n => [n.id, n.sheet as Record<string, unknown>])))
    const p = npcSheets.then(m => build(id, presetVersionId, m.get(id) ?? {}))
    p.catch(() => { loading.delete(id); npcSheets = null })
    loading.set(id, p)
    return p
  }

  return { byId, character, npc }
}

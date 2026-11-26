import type { SheetUi, SheetUiCollection } from './types'

export interface SheetField { path: string; label: string; widget: string; value: unknown }
export interface SheetSection {
  id: string; label: string; numeric: boolean; fields: SheetField[]
  /** Every field is a rating (dots): attributes, skills. */
  rated?: boolean
  /** The section is read-only for everyone (computed values). */
  readOnly?: boolean
  /** Tab declared by the preset (sheet-ui `tab`), if any. */
  tab?: string
  /** Lists in this section (attacks, items, rituals): column labels and the rows the viewer may read. */
  collections?: { path: string; label: string; columns: { field: string; label: string }[]; rows: Record<string, unknown>[] }[]
}

export type SheetTab = 'ficha' | 'valores' | 'itens' | 'detalhes'
/** Which tab a section belongs to: the preset's own `tab`, else ratings / computed values / lists / the rest. */
export const LONG_SECTION = 8
export function tabOf(section: SheetSection): string {
  if (section.tab) return section.tab
  if (section.collections?.length && !section.fields.length) return 'Itens'
  if (section.numeric && section.readOnly) return 'Valores'
  // A long list of ratings (skills, disciplines) gets its own tab, named after it.
  if (section.numeric) return section.fields.length > LONG_SECTION ? section.label : 'Ficha'
  return 'Detalhes'
}

/** Reads a JSON pointer from a sheet document (no exceptions: missing → undefined). */
export function readPointer(doc: unknown, pointer: string): unknown {
  let node: unknown = doc
  for (const raw of pointer.split('/').slice(1)) {
    const key = raw.replace(/~1/g, '/').replace(/~0/g, '~')
    if (node === null || typeof node !== 'object' || !(key in (node as object))) return undefined
    node = (node as Record<string, unknown>)[key]
  }
  return node
}

const READABLE = ['read_only', 'editable', 'approval_required']

/** Sub-schema at a pointer (explicit `properties` only, like the engine's SchemaNavigator). */
function schemaAt(schema: Record<string, unknown> | undefined, pointer: string): Record<string, unknown> | undefined {
  let node: unknown = schema
  for (const raw of pointer.split('/').slice(1)) {
    const key = raw.replace(/~1/g, '/').replace(/~0/g, '~')
    node = (node as { properties?: Record<string, unknown> } | undefined)?.properties?.[key]
    if (node === undefined) return undefined
  }
  return node as Record<string, unknown>
}

/**
 * Groups the sheet the viewer received (already projected by the server) by the preset's
 * sections. Fields the server left out are absent, except numbers a player may read that were
 * never written: those show the preset's default (then minimum), the value the engine uses.
 */
export function sheetSections(ui: SheetUi, sheet: Record<string, unknown>, schema?: Record<string, unknown>, optionLabels?: Record<string, Record<string, string>>): SheetSection[] {
  const fallback = (path: string, widget: string, access: string | undefined): unknown => {
    if (!schema || (widget !== 'dot_rating' && widget !== 'integer') || !READABLE.includes(access ?? '')) return undefined
    const node = schemaAt(schema, path)
    if (node?.type !== 'integer') return undefined
    return typeof node.default === 'number' ? node.default : typeof node.minimum === 'number' ? node.minimum : undefined
  }
  const defaultAccess = (ui as { default_access?: string }).default_access
  return ui.sections.map(section => {
    const fields = section.elements
      .filter((e): e is SheetField & { kind: 'field' } => e.kind === 'field')
      .map(e => {
        const widget = (e as { widget: string }).widget
        const raw = readPointer(sheet, e.path)
        // Choice values show the preset's label (e.g. a lineage name), never the stored id.
        const labelled = raw !== undefined && raw !== null ? optionLabels?.[e.path]?.[String(raw)] : undefined
        const value = labelled ?? raw ?? fallback(e.path, widget, (e as { access?: string }).access ?? section.access ?? defaultAccess)
        return { path: e.path, label: e.label, widget, value }
      })
      .filter(f => f.value !== undefined && f.value !== '')
    const collections = section.elements
      .filter((e): e is SheetUiCollection => e.kind === 'collection')
      .map(e => {
        const columns = (e.item_fields ?? []).map(f => ({ field: f.field, label: f.label }))
        const raw = readPointer(sheet, e.path)
        const rows = (Array.isArray(raw) ? raw : []).filter((r): r is Record<string, unknown> => typeof r === 'object' && r !== null)
          .map(r => Object.fromEntries(columns.map(c => [c.field, optionLabels?.[`${e.path}/*/${c.field}`]?.[String(r[c.field])] ?? r[c.field]])))
        return { path: e.path, label: e.label, columns, rows }
      })
      .filter(c => c.rows.length > 0)
    const numeric = fields.length > 0 && fields.every(f => f.widget === 'dot_rating' || f.widget === 'integer')
    const rated = fields.length > 0 && fields.every(f => f.widget === 'dot_rating')
    const readOnly = ((section as { access?: string }).access ?? defaultAccess) === 'read_only'
    return { id: section.id, label: section.label, numeric, fields, rated, readOnly, tab: (section as { tab?: string }).tab, collections }
  }).filter(s => s.fields.length > 0 || s.collections.length > 0)
}

/** Deep copy of plain JSON data, including Vue reactive proxies (structuredClone rejects those). */
export const plain = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

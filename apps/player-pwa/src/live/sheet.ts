import type { SheetUi } from './types'

export interface SheetField { path: string; label: string; widget: string; value: unknown }
export interface SheetSection { id: string; label: string; numeric: boolean; fields: SheetField[] }

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
export function sheetSections(ui: SheetUi, sheet: Record<string, unknown>, schema?: Record<string, unknown>): SheetSection[] {
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
        const value = readPointer(sheet, e.path) ?? fallback(e.path, widget, (e as { access?: string }).access ?? section.access ?? defaultAccess)
        return { path: e.path, label: e.label, widget, value }
      })
      .filter(f => f.value !== undefined && f.value !== '')
    const numeric = fields.length > 0 && fields.every(f => f.widget === 'dot_rating' || f.widget === 'integer')
    return { id: section.id, label: section.label, numeric, fields }
  }).filter(s => s.fields.length > 0)
}

/** Deep copy of plain JSON data, including Vue reactive proxies (structuredClone rejects those). */
export const plain = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

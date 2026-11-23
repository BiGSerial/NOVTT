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

/**
 * Groups the sheet the viewer received (already projected by the server) by the preset's
 * sections. Fields the server left out are simply absent; nothing is inferred client-side.
 */
export function sheetSections(ui: SheetUi, sheet: Record<string, unknown>): SheetSection[] {
  return ui.sections.map(section => {
    const fields = section.elements
      .filter((e): e is SheetField & { kind: 'field' } => e.kind === 'field')
      .map(e => ({ path: e.path, label: e.label, widget: (e as { widget: string }).widget, value: readPointer(sheet, e.path) }))
      .filter(f => f.value !== undefined && f.value !== '')
    const numeric = fields.length > 0 && fields.every(f => f.widget === 'dot_rating' || f.widget === 'integer')
    return { id: section.id, label: section.label, numeric, fields }
  }).filter(s => s.fields.length > 0)
}

/** Deep copy of plain JSON data, including Vue reactive proxies (structuredClone rejects those). */
export const plain = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

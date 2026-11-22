// Client-side mirrors of the versioned contracts (contracts/presets, contracts/sheets).
// The server is authoritative: these types only drive presentation and draft editing.

export type FieldAccess = 'hidden' | 'read_only' | 'editable' | 'approval_required' | 'master_only'
export type Widget = 'text' | 'textarea' | 'integer' | 'number' | 'boolean' | 'dot_rating' | 'select'
export type Scalar = string | number | boolean | null

export interface ItemFieldUi { field: string; label: string; widget: Widget; access?: FieldAccess }
export interface FieldElement { kind: 'field'; path: string; label: string; widget: Widget; access?: FieldAccess; description?: string }
export interface CollectionElement { kind: 'collection'; path: string; label: string; access?: FieldAccess; max_items?: number; item_fields: ItemFieldUi[] }
export type Element = FieldElement | CollectionElement
export interface Section { id: string; label: string; access?: FieldAccess; elements: Element[] }
export interface SheetUi { contract: 'novtt.sheet-ui/1'; default_access: FieldAccess; sections: Section[] }

export type Operation =
  | { id: string; type: 'set_field'; path: string; value: Scalar; expected?: unknown }
  | { id: string; type: 'unset_field'; path: string; expected?: unknown }
  | { id: string; type: 'add_item'; path: string; item_id: string; value: Record<string, Scalar> }
  | { id: string; type: 'set_item_field'; path: string; item_id: string; field: string; value: Scalar; expected?: unknown }
  | { id: string; type: 'remove_item'; path: string; item_id: string }
  | { id: string; type: 'reorder_items'; path: string; order: string[] }

export interface ChangeSet { contract: 'novtt.sheet-change-set/1'; base_sheet_revision: number; operations: Operation[] }

export interface ValidationIssue { rule_id: string; severity: 'error' | 'warning'; operation_ids: string[]; paths: string[]; params: Record<string, Scalar> }
export interface ValidationResult { contract: 'novtt.validation-result/1'; valid: boolean; issues: ValidationIssue[] }

export type Sheet = Record<string, unknown>

/** Effective access: element > section > default (same resolution as the engine's SheetLayout). */
export function accessOf(ui: SheetUi, section: Section, element: { access?: FieldAccess }): FieldAccess {
  return element.access ?? section.access ?? ui.default_access
}

export function canPropose(access: FieldAccess): boolean {
  return access === 'editable' || access === 'approval_required'
}

export function canRead(access: FieldAccess): boolean {
  return access === 'read_only' || canPropose(access)
}

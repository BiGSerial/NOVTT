import { read } from './pointer'
import type { ChangeSet, Operation, Scalar, Sheet } from './types'

interface Item { id: string; [field: string]: Scalar }

/**
 * Local draft editor. Keeps at most one operation per field (the last intent) and drops an
 * operation when the value returns to the official one, so the proposal stays minimal and the
 * master reviews exactly what changed. Nothing here is authoritative: the server validates.
 */
export class DraftModel {
  private operations: Operation[] = []
  private sequence = 0

  constructor(private readonly official: Sheet, private readonly baseRevision: number, initial?: ChangeSet) {
    if (initial) {
      this.operations = [...initial.operations]
      // Continue after the highest saved id: removed operations leave gaps (op6, op7…), so the
      // count would reuse an id still in the set and the server rejects duplicate ids.
      this.sequence = this.operations.reduce((max, o) => Math.max(max, Number(/^op(\d+)$/.exec(o.id)?.[1] ?? 0)), 0)
    }
  }

  get changeSet(): ChangeSet {
    return { contract: 'novtt.sheet-change-set/1', base_sheet_revision: this.baseRevision, operations: [...this.operations] }
  }

  get size(): number {
    return this.operations.length
  }

  officialValue(path: string): unknown {
    return read(this.official, path)
  }

  /** Current value as the draft would leave it. */
  value(path: string): unknown {
    const op = this.operations.find(o => o.path === path && (o.type === 'set_field' || o.type === 'unset_field'))
    if (op?.type === 'set_field') return op.value
    if (op?.type === 'unset_field') return undefined
    return this.officialValue(path)
  }

  setField(path: string, value: Scalar): void {
    this.operations = this.operations.filter(o => !(o.path === path && (o.type === 'set_field' || o.type === 'unset_field')))
    const official = this.officialValue(path)
    if (official === value) return
    this.operations.push({ id: this.nextId(), type: 'set_field', path, value, expected: official ?? null })
  }

  items(path: string): Item[] {
    const base = (read(this.official, path) as Item[] | undefined) ?? []
    let items: Item[] = base.map(i => ({ ...i }))
    for (const op of this.operations.filter(o => o.path === path)) {
      if (op.type === 'add_item') items.push({ id: op.item_id, ...op.value })
      if (op.type === 'remove_item') items = items.filter(i => i.id !== op.item_id)
      if (op.type === 'set_item_field') items = items.map(i => (i.id === op.item_id ? { ...i, [op.field]: op.value } : i))
    }
    return items
  }

  addItem(path: string, value: Record<string, Scalar>): string {
    const itemId = `i${Date.now().toString(36)}${(this.sequence + 1).toString(36)}`
    this.operations.push({ id: this.nextId(), type: 'add_item', path, item_id: itemId, value })
    return itemId
  }

  removeItem(path: string, itemId: string): void {
    const added = this.operations.findIndex(o => o.type === 'add_item' && o.path === path && o.item_id === itemId)
    if (added >= 0) {
      // Removing an item added in this draft simply forgets it (and its field edits).
      this.operations = this.operations.filter(o => !('item_id' in o && o.path === path && o.item_id === itemId))
      return
    }
    this.operations.push({ id: this.nextId(), type: 'remove_item', path, item_id: itemId })
  }

  /** Operation ids touching a path (used to link validation issues to fields). */
  operationIdsFor(path: string): string[] {
    return this.operations.filter(o => o.path === path).map(o => o.id)
  }

  private nextId(): string {
    do this.sequence += 1
    while (this.operations.some(o => o.id === `op${this.sequence}`))
    return `op${this.sequence}`
  }
}

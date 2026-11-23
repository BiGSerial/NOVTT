import { describe, expect, it } from 'vitest'
import { DraftModel } from '../src/sheets/draft'

describe('DraftModel', () => {
  const official = { attributes: { might: 2 }, merits: [{ id: 'm1', name: 'A', cost: 1 }] }

  it('never reuses an operation id when reopening a saved draft with gaps', () => {
    const saved = { contract: 'novtt.sheet-change-set/1' as const, base_sheet_revision: 0, operations: [
      { id: 'op6', type: 'set_field' as const, path: '/skills/lore', value: 3, expected: null },
      { id: 'op7', type: 'set_field' as const, path: '/attributes/wits', value: 2, expected: 1 },
    ] }
    const draft = new DraftModel({ attributes: { wits: 1 } }, 0, saved)
    for (const path of ['/attributes/might', '/attributes/agility', '/skills/climb']) draft.setField(path, 2)
    const ids = draft.changeSet.operations.map(o => o.id)
    expect(new Set(ids).size).toBe(ids.length)
    expect(ids).toEqual(['op6', 'op7', 'op8', 'op9', 'op10'])
  })

  it('keeps one operation per field and records the value the author saw', () => {
    const draft = new DraftModel(official, 3)
    draft.setField('/attributes/might', 3)
    draft.setField('/attributes/might', 4)

    expect(draft.changeSet).toEqual({
      contract: 'novtt.sheet-change-set/1',
      base_sheet_revision: 3,
      operations: [{ id: 'op2', type: 'set_field', path: '/attributes/might', value: 4, expected: 2 }],
    })
  })

  it('drops the operation when the value returns to the official one', () => {
    const draft = new DraftModel(official, 3)
    draft.setField('/attributes/might', 5)
    draft.setField('/attributes/might', 2)
    expect(draft.size).toBe(0)
    expect(draft.value('/attributes/might')).toBe(2)
  })

  it('forgets items added in the draft and proposes removal of official ones', () => {
    const draft = new DraftModel(official, 0)
    const added = draft.addItem('/merits', { name: 'B', cost: 2 })
    expect(draft.items('/merits').map(i => i.id)).toEqual(['m1', added])
    draft.removeItem('/merits', added)
    expect(draft.size).toBe(0)
    draft.removeItem('/merits', 'm1')
    expect(draft.changeSet.operations).toEqual([{ id: 'op2', type: 'remove_item', path: '/merits', item_id: 'm1' }])
    expect(draft.items('/merits')).toEqual([])
    expect(official.merits).toHaveLength(1)
  })
})

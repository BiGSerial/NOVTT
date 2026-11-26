import { describe, expect, it } from 'vitest'
import { tabOf, type SheetSection } from '../src/live/sheet'

const section = (over: Partial<SheetSection>): SheetSection => ({ id: 'x', label: 'X', numeric: false, fields: [], ...over })
const fields = (n: number) => Array.from({ length: n }, (_, i) => ({ path: `/x/${i}`, label: `F${i}`, widget: 'integer', value: 0 }))

describe('sheet tabs', () => {
  it('groups ratings, long rating lists, computed values, lists and the rest', () => {
    expect(tabOf(section({ numeric: true, fields: fields(5) }))).toBe('Ficha')
    expect(tabOf(section({ label: 'Perícias', numeric: true, fields: fields(28) }))).toBe('Perícias')
    expect(tabOf(section({ numeric: true, readOnly: true, fields: fields(6) }))).toBe('Valores')
    expect(tabOf(section({ collections: [{ path: '/inv', label: 'Itens', columns: [], rows: [{}] }] }))).toBe('Itens')
    expect(tabOf(section({ fields: [{ path: '/o', label: 'Origem', widget: 'select', value: 'x' }] }))).toBe('Detalhes')
  })

  it('a preset tab wins over the grouping', () => {
    expect(tabOf(section({ tab: 'Combate', numeric: true, fields: fields(28) }))).toBe('Combate')
  })
})

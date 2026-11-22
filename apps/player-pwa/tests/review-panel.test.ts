// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { allDecided, reasonError, ReviewPanel, type ChangeRequestView, type Decision } from '../../master-web/resources/js/review-panel'
import { axeViolations } from './support'

function view(overrides: Partial<ChangeRequestView> = {}): ChangeRequestView {
  return {
    id: 'cr1',
    status: 'submitted',
    review_version: 1,
    sections: [
      { section_id: 'attributes', label: 'Atributos', operations: [
        { id: 'a', type: 'set_field', path: '/attributes/might', section_id: 'attributes', value: 3, decision: null },
        { id: 'b', type: 'set_field', path: '/attributes/agility', section_id: 'attributes', value: 2, decision: { decision: 'rejected', reason: 'Não combina com o conceito.', final: false } },
      ] },
      { section_id: 'merits', label: 'Qualidades', operations: [
        { id: 'c', type: 'add_item', path: '/merits', section_id: 'merits', item_id: 'm1', value: { name: '<img src=x onerror=alert(1)>' }, decision: null },
      ] },
    ],
    ...overrides,
  }
}

describe('ReviewPanel', () => {
  let root: HTMLElement
  beforeEach(() => {
    document.body.replaceChildren()
    root = document.createElement('section')
    document.body.append(root)
  })

  it('validates rejection reasons with the same bounds as the server', () => {
    expect(reasonError('curto')).toMatch(/pelo menos 10/)
    expect(reasonError('          ')).toMatch(/pelo menos 10/)
    expect(reasonError('x'.repeat(1001))).toMatch(/no máximo 1000/)
    expect(reasonError('Motivo suficiente.')).toBeNull()
  })

  it('batch-approves within a section and revisits rejections only individually', async () => {
    const decide = vi.fn(async (_id: string, _v: number, _d: Decision[]) => view({ review_version: 2 }))
    new ReviewPanel(root, view(), { decide, finalize: vi.fn() })
    const section = root.querySelector('[data-section=attributes]')!
    section.querySelectorAll<HTMLInputElement>('input[type=checkbox]').forEach(c => { c.checked = true })
    section.querySelector<HTMLButtonElement>('button.approve-batch')!.click()
    await Promise.resolve()

    expect(decide).toHaveBeenCalledWith('cr1', 1, [
      { operation_ids: ['a'], decision: 'approved' },
      { operation_ids: ['b'], decision: 'approved', override: true },
    ])
  })

  it('requires a valid reason before sending a rejection and moves focus to the field', async () => {
    const decide = vi.fn(async () => view())
    new ReviewPanel(root, view(), { decide, finalize: vi.fn() })
    const item = root.querySelector('[data-operation=a]')!
    const textarea = item.querySelector('textarea')!
    const confirm = [...item.querySelectorAll('button')].find(b => b.textContent === 'Confirmar rejeição')!

    textarea.value = 'curto'
    confirm.click()
    expect(decide).not.toHaveBeenCalled()
    expect(textarea.getAttribute('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(textarea)

    textarea.value = '  Vigor acima do permitido.  '
    confirm.click()
    await Promise.resolve()
    expect(decide).toHaveBeenCalledWith('cr1', 1, [{ operation_ids: ['a'], decision: 'rejected', reason: 'Vigor acima do permitido.' }])
  })

  it('enables finalization only when every operation has a decision', async () => {
    const decided = view()
    decided.sections.forEach(s => s.operations.forEach(o => { o.decision ??= { decision: 'approved', reason: null, final: false } }))
    expect(allDecided(view())).toBe(false)
    expect(allDecided(decided)).toBe(true)

    new ReviewPanel(root, view(), { decide: vi.fn(), finalize: vi.fn() })
    expect(root.querySelector<HTMLButtonElement>('.finalize')!.disabled).toBe(true)

    root.replaceChildren()
    const finalize = vi.fn(async () => ({ ...decided, status: 'applied' }))
    new ReviewPanel(root, decided, { decide: vi.fn(), finalize }, () => 'key-1')
    root.querySelector<HTMLButtonElement>('.finalize')!.click()
    await Promise.resolve()
    await Promise.resolve()
    expect(finalize).toHaveBeenCalledWith('cr1', 1, 'key-1')
    expect(root.querySelector('[role=status]')!.textContent).toContain('aplicadas')
  })

  it('never interprets proposal values as HTML', () => {
    new ReviewPanel(root, view(), { decide: vi.fn(), finalize: vi.fn() })
    expect(root.querySelector('img')).toBeNull()
    expect(root.textContent).toContain('<img src=x onerror=alert(1)>')
  })

  it('has no detectable WCAG A/AA violations', async () => {
    new ReviewPanel(root, view(), { decide: vi.fn(), finalize: vi.fn() })
    expect(await axeViolations(root)).toEqual([])
  })
})

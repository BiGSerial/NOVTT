// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h, nextTick, type App } from 'vue'
import SheetEditor from '../src/components/SheetEditor.vue'
import { DraftModel } from '../src/sheets/draft'
import type { SheetUi, ValidationIssue } from '../src/sheets/types'
import { axeViolations, fixture } from './support'

const ui = fixture<SheetUi>('sheet.ui.json')
const schema = fixture<Record<string, unknown>>('sheet.schema.json')
let app: App | null = null

const budgetLabels = { attribute_points: 'Pontos de atributo', xp: 'Experiência' }

function mount(draft: DraftModel, issues: ValidationIssue[] = [], readonly = false): HTMLElement {
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h('main', [h(SheetEditor, { ui, schema, draft, issues, revision: 1, readonly, budgetLabels })]) })
  app.mount(root)
  return root
}

afterEach(() => {
  app?.unmount()
  app = null
  document.body.replaceChildren()
})

describe('SheetEditor', () => {
  it('groups fields by section and never renders hidden or master-only fields', () => {
    const root = mount(new DraftModel({ identity: { name: 'Ana' }, secrets: { hook: 'segredo' }, master: { notes: 'trama' } }, 1))
    const legends = [...root.querySelectorAll('fieldset.section > legend')].map(l => l.textContent)

    expect(legends).toEqual(['Identidade', 'Atributos', 'Perícias', 'Qualidades'])
    expect(root.textContent).not.toContain('segredo')
    expect(root.textContent).not.toContain('trama')
    expect(root.textContent).not.toContain('Notas do mestre')
  })

  it('dot ratings are keyboard radio groups that update the draft', async () => {
    const draft = new DraftModel({ attributes: { might: 2 } }, 1)
    const root = mount(draft)
    const group = root.querySelector('[role=radiogroup][id="f-attributes-might"]')!
    const radios = [...group.querySelectorAll<HTMLButtonElement>('[role=radio]')]

    expect(group.getAttribute('aria-labelledby')).toBe('f-attributes-might-label')
    expect(radios).toHaveLength(5) // minimum 1, maximum 5
    expect(radios.filter(r => r.tabIndex === 0)).toHaveLength(1)
    expect(radios[1].getAttribute('aria-checked')).toBe('true')

    radios[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
    await nextTick()
    expect(draft.value('/attributes/might')).toBe(3)
    radios[2].dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }))
    expect(draft.value('/attributes/might')).toBe(5)
  })

  it('links every error to its field and summarizes them in an alert', () => {
    const issues: ValidationIssue[] = [
      { rule_id: 'budget.insufficient', severity: 'error', operation_ids: ['op1'], paths: ['/attributes/might'], params: { budget: 'xp', required: 8, available: 2 } },
      { rule_id: 'validation.restricted', severity: 'error', operation_ids: [], paths: [], params: {} },
    ]
    const root = mount(new DraftModel({}, 1), issues)
    const summary = root.querySelector('.error-summary')!

    expect(summary.getAttribute('role')).toBe('alert')
    expect(summary.querySelector('a')!.getAttribute('href')).toBe('#f-attributes-might')
    expect(summary.textContent).toContain('Saldo insuficiente em Experiência: precisa de 8, restam 2.')
    expect(root.textContent).not.toMatch(/\bxp\b/)
    const group = root.querySelector('#f-attributes-might')!
    expect(group.getAttribute('aria-invalid')).toBe('true')
    expect(root.querySelector(`#${group.getAttribute('aria-describedby')}`)!.textContent).toContain('Saldo insuficiente em Experiência')
  })

  it('never shows a raw budget id, even without a label', () => {
    const root = mount(new DraftModel({}, 1), [
      { rule_id: 'budget.insufficient', severity: 'error', operation_ids: [], paths: ['/skills/climb'], params: { budget: 'skill_points', required: 1, available: 1 } },
    ])
    expect(root.textContent).toContain('Saldo insuficiente: precisa de 1, resta 1.')
    expect(root.textContent).not.toContain('skill_points')
  })

  it('says once per section that changes need approval, and does not repeat the section title', () => {
    const root = mount(new DraftModel({}, 1))
    const notes = [...root.querySelectorAll('.help')].filter(p => p.textContent!.includes('aprovação do mestre'))
    expect(notes).toHaveLength(3) // Atributos, Perícias, Qualidades — not one per field
    const heading = [...root.querySelectorAll('.collection h3')].find(h => h.textContent === 'Qualidades')!
    expect(heading.classList.contains('sr-only')).toBe(true)
  })

  it('keeps keyboard focus on the rating after a change (no remount)', async () => {
    const root = mount(new DraftModel({ attributes: { might: 2 } }, 1))
    const radios = () => [...root.querySelectorAll<HTMLButtonElement>('[id="f-attributes-might"] [role=radio]')]
    const before = radios()
    before[1].focus()
    before[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
    await nextTick()
    expect(radios()[2]).toBe(before[2]) // same DOM node
    expect(document.activeElement).toBe(before[2])
    expect(before[2].getAttribute('aria-checked')).toBe('true')
  })

  it('read-only mode shows values without inputs', () => {
    const root = mount(new DraftModel({ attributes: { might: 4 } }, 1), [], true)
    expect(root.querySelectorAll('input, textarea, [role=radio]')).toHaveLength(0)
    expect(root.textContent).toContain('Vigor')
  })

  it('has no detectable WCAG A/AA violations', async () => {
    const root = mount(new DraftModel({ identity: { name: 'Ana' }, merits: [{ id: 'm1', name: 'Ágil', cost: 2 }] }, 1), [
      { rule_id: 'schema.maximum', severity: 'error', operation_ids: [], paths: ['/identity/name'], params: { max: 3 } },
    ])
    expect(await axeViolations(root)).toEqual([])
  })
})

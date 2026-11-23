/**
 * Master review panel (SPEC-SPRINT-001 rules 4 and 7): operations grouped by sheet section,
 * batch approval within one section (provisional only), individual rejection with its own
 * reason (10–1000 characters), explicit finalization once every operation is decided.
 * Framework-free DOM; all text is set with textContent (no HTML injection).
 */

export interface OperationView {
  id: string
  type: string
  path: string
  section_id: string
  value?: unknown
  item_id?: string | null
  field?: string | null
  decision: { decision: 'approved' | 'rejected'; reason: string | null; final: boolean } | null
}

export interface ChangeRequestView {
  id: string
  status: string
  review_version: number
  sections: { section_id: string; label: string; operations: OperationView[] }[]
  validation?: { valid: boolean; issues: { rule_id: string; operation_ids: string[]; paths: string[] }[] }
}

export type Decision =
  | { operation_ids: string[]; decision: 'approved'; override?: true }
  | { operation_ids: [string]; decision: 'rejected'; reason: string }

export interface ReviewApi {
  decide(requestId: string, reviewVersion: number, decisions: Decision[]): Promise<ChangeRequestView>
  finalize(requestId: string, reviewVersion: number, idempotencyKey: string): Promise<ChangeRequestView>
}

export const REASON_MIN = 10
export const REASON_MAX = 1000

export function reasonError(reason: string): string | null {
  const length = reason.trim().length
  if (length < REASON_MIN) return `O motivo precisa de pelo menos ${REASON_MIN} caracteres.`
  if (length > REASON_MAX) return `O motivo pode ter no máximo ${REASON_MAX} caracteres.`
  return null
}

export function allDecided(view: ChangeRequestView): boolean {
  return view.sections.every(s => s.operations.every(o => o.decision !== null))
}

function describe(op: OperationView): string {
  const target = op.item_id ? `${op.path} › ${op.item_id}${op.field ? ` › ${op.field}` : ''}` : op.path
  const value = op.value === undefined || op.value === null ? '' : ` → ${typeof op.value === 'object' ? JSON.stringify(op.value) : String(op.value)}`
  const verb: Record<string, string> = {
    set_field: 'Alterar', unset_field: 'Limpar', add_item: 'Adicionar', set_item_field: 'Alterar item', remove_item: 'Remover', reorder_items: 'Reordenar',
  }
  return `${verb[op.type] ?? op.type}: ${target}${value}`
}

function el<K extends keyof HTMLElementTagNameMap>(tag: K, attrs: Record<string, string> = {}, text?: string): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag)
  for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v)
  if (text !== undefined) node.textContent = text
  return node
}

export class ReviewPanel {
  private view: ChangeRequestView
  private readonly status: HTMLElement

  constructor(private readonly root: HTMLElement, initial: ChangeRequestView, private readonly api: ReviewApi, private readonly newKey: () => string = () => crypto.randomUUID()) {
    this.view = initial
    this.status = el('p', { role: 'status', 'aria-live': 'polite', class: 'review-status' })
    this.render()
  }

  private render(): void {
    this.root.replaceChildren()
    this.root.append(el('h2', {}, 'Revisão da proposta'))
    const issuesByOp = new Map<string, number>()
    for (const issue of this.view.validation?.issues ?? []) for (const id of issue.operation_ids) issuesByOp.set(id, (issuesByOp.get(id) ?? 0) + 1)

    for (const section of this.view.sections) {
      const fieldset = el('fieldset', { 'data-section': section.section_id })
      fieldset.append(el('legend', {}, section.label))
      const list = el('ul')
      const checkboxes: HTMLInputElement[] = []
      for (const op of section.operations) {
        const item = el('li', { 'data-operation': op.id })
        const id = `op-${this.view.id}-${op.id}`
        const box = el('input', { type: 'checkbox', id, value: op.id })
        box.disabled = op.decision?.final === true
        checkboxes.push(box)
        const label = el('label', { for: id }, describe(op))
        const state = el('span', { class: 'decision' }, op.decision === null ? 'Pendente' : op.decision.decision === 'approved' ? 'Aprovada (provisória)' : 'Rejeitada (provisória)')
        if (op.decision?.final) state.textContent = op.decision.decision === 'approved' ? 'Aprovada' : 'Rejeitada'
        item.append(box, label, state)
        if (op.decision?.reason) item.append(el('p', { class: 'reason' }, `Motivo: ${op.decision.reason}`))
        if (issuesByOp.has(op.id)) item.append(el('p', { class: 'error' }, 'A engine aponta um problema nesta operação.'))
        if (!op.decision?.final) item.append(this.rejectForm(op))
        list.append(item)
      }
      const approve = el('button', { type: 'button', class: 'approve-batch' }, `Aprovar selecionadas em ${section.label}`)
      approve.addEventListener('click', () => {
        const ids = checkboxes.filter(c => c.checked).map(c => c.value)
        if (ids.length === 0) return this.announce('Selecione ao menos uma operação.')
        const rejected = section.operations.filter(o => ids.includes(o.id) && o.decision?.decision === 'rejected').map(o => o.id)
        // A batch never overrides a rejection; each one must be revisited individually.
        const batch = ids.filter(id => !rejected.includes(id))
        void this.send([
          ...(batch.length ? [{ operation_ids: batch, decision: 'approved' as const }] : []),
          ...rejected.map(id => ({ operation_ids: [id], decision: 'approved' as const, override: true as const })),
        ])
      })
      fieldset.append(list, approve)
      this.root.append(fieldset)
    }

    const finalize = el('button', { type: 'button', class: 'finalize' }, 'Finalizar revisão')
    finalize.disabled = !allDecided(this.view) || !['in_review', 'awaiting_resolution', 'submitted'].includes(this.view.status)
    finalize.setAttribute('aria-describedby', 'finalize-help')
    finalize.addEventListener('click', () => void this.finalize())
    this.root.append(finalize, el('p', { id: 'finalize-help', class: 'help' }, 'Disponível quando todas as operações tiverem decisão. Aplica o conjunto aprovado inteiro ou nada.'), this.status)
  }

  private rejectForm(op: OperationView): HTMLElement {
    const details = el('details')
    details.append(el('summary', {}, 'Rejeitar com motivo'))
    const id = `reason-${this.view.id}-${op.id}`
    const label = el('label', { for: id }, 'Motivo da rejeição')
    const textarea = el('textarea', { id, maxlength: String(REASON_MAX), 'aria-describedby': `${id}-count` })
    const count = el('span', { id: `${id}-count`, class: 'help' }, `0 de ${REASON_MAX} caracteres (mínimo ${REASON_MIN})`)
    const error = el('p', { class: 'error', id: `${id}-error` })
    textarea.addEventListener('input', () => { count.textContent = `${textarea.value.trim().length} de ${REASON_MAX} caracteres (mínimo ${REASON_MIN})` })
    const button = el('button', { type: 'button' }, 'Confirmar rejeição')
    button.addEventListener('click', () => {
      const problem = reasonError(textarea.value)
      if (problem) {
        error.textContent = problem
        textarea.setAttribute('aria-invalid', 'true')
        textarea.setAttribute('aria-describedby', `${id}-count ${id}-error`)
        textarea.focus()
        return
      }
      void this.send([{ operation_ids: [op.id], decision: 'rejected', reason: textarea.value.trim() }])
    })
    details.append(label, textarea, count, error, button)
    return details
  }

  private async send(decisions: Decision[]): Promise<void> {
    try {
      this.view = await this.api.decide(this.view.id, this.view.review_version, decisions)
      this.render()
      this.announce('Decisões registradas (provisórias).')
    } catch (error) {
      this.announce(error instanceof Error ? `Não foi possível registrar: ${error.message}` : 'Falha ao registrar.')
    }
  }

  private async finalize(): Promise<void> {
    try {
      this.view = await this.api.finalize(this.view.id, this.view.review_version, this.newKey())
      this.render()
      this.announce(this.view.status === 'applied' ? 'Alterações aprovadas aplicadas à ficha.' : 'Proposta encerrada sem alterações.')
    } catch (error) {
      this.announce(error instanceof Error ? `Não foi possível finalizar: ${error.message}` : 'Falha ao finalizar.')
    }
  }

  private announce(message: string): void {
    this.status.textContent = message
    if (!this.status.isConnected) this.root.append(this.status)
  }
}

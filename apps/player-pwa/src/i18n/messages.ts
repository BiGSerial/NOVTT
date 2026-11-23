// pt-BR texts for stable server codes. Contracts carry codes, never prose (ADR 0007 direction);
// this module is the only place mapping codes to user-facing text.
type Params = Record<string, string | number | boolean | null>

const messages: Record<string, (p: Params) => string> = {
  'op.path_not_allowed': () => 'Este campo não pode ser alterado por você.',
  'op.precondition_failed': () => 'O valor mudou desde que você começou. Atualize o rascunho.',
  'op.item_not_found': () => 'O item não existe mais.',
  'op.item_exists': () => 'Já existe um item com este identificador.',
  'op.collection_full': p => `Limite de ${p.max_items} itens atingido.`,
  // The budget id is an internal code: show the preset's label, or nothing.
  'budget.insufficient': p => `Saldo insuficiente${p.budget_label ? ` em ${p.budget_label}` : ''}: precisa de ${p.required}, resta${Number(p.available) === 1 ? '' : 'm'} ${p.available}.`,
  'budget.no_cost_rule_for_phase': () => 'Este valor não pode mudar nesta fase do personagem.',
  'limit.phase_max': p => `O máximo nesta fase é ${p.max}.`,
  'dependency.unmet': () => 'Este valor depende de outro campo que ainda não foi preenchido.',
  'schema.maximum': p => `O máximo é ${p.max ?? p.maximum ?? ''}.`.replace(' .', '.'),
  'schema.minimum': p => `O mínimo é ${p.min ?? p.minimum ?? ''}.`.replace(' .', '.'),
  'schema.maxLength': p => `Use no máximo ${p.max ?? ''} caracteres.`,
  'schema.type': () => 'Tipo de valor inválido.',
  'change_set.invalid': () => 'O rascunho ficou inconsistente. Recarregue a página e tente de novo.',
  'draft.stale': () => 'A ficha oficial mudou. Revise o rascunho antes de enviar.',
  'draft.version_conflict': () => 'O rascunho foi alterado em outra janela. Recarregue.',
  'change_request.active_exists': () => 'Já existe uma proposta em análise para este personagem.',
  'validation.restricted': () => 'Há um problema que depende de informações reservadas ao mestre.',
  'auth.unauthenticated': () => 'Entre na sua conta para continuar.',
  'resource.not_found': () => 'Não encontrado.',
  'rate_limit.exceeded': () => 'Muitas tentativas. Aguarde um pouco.',
  'network.error': () => 'Sem conexão com o servidor.',
}

export function t(code: string, params: Params = {}): string {
  return (messages[code] ?? (() => 'Não foi possível concluir a operação.'))(params)
}

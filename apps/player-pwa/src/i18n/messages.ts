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
  // Preset engine (rules/2): choices, constraints, progression, resources, derived values.
  'op.derived_read_only': () => 'Este valor é calculado pela ficha e não pode ser editado.',
  'choice.prerequisite_unmet': () => 'Esta opção exige um pré-requisito que a ficha ainda não cumpre.',
  'choice.unknown_option': () => 'Esta opção não existe neste sistema.',
  'constraint.failed': () => 'Esta combinação não é permitida pelas regras do sistema.',
  'budget.overdrawn': () => 'Esta mudança deixa pontos já gastos sem saldo. Devolva alguns pontos antes.',
  'progression.level_not_earned': p => `Ainda não há experiência para o nível ${p.requested} (liberado até o ${p.earned}).`,
  'progression.level_skip': () => 'Suba um nível por vez.',
  'progression.level_decrease': () => 'O nível não pode diminuir por proposta.',
  'resource.out_of_range': p => `O valor precisa ficar entre ${p.min} e ${p.max}.`,
  'inventory.over_capacity': p => `Carga acima do limite (${p.load} de ${p.capacity}).`,
  'derived.evaluation_failed': () => 'Não foi possível calcular este valor com os dados atuais.',
  'character.creation_incomplete': () => 'A criação ainda tem etapas incompletas.',
  'character.locked_by_session': () => 'A ficha está travada durante a sessão ao vivo. Envie a proposta depois.',
  'character.already_current': () => 'A ficha já está na versão da campanha.',
  'character.revision_mismatch': () => 'A ficha mudou enquanto você olhava. Confira de novo.',
  'character.source_unavailable': () => 'Esse personagem não pode ser trazido.',
  'character.not_yours': () => 'Só o dono do personagem ou o mestre pode fazer isso.',
  'campaign.preset_upgrade_unavailable': () => 'Essa versão não pode ser usada nesta campanha.',
  'campaign.revision_mismatch': () => 'A campanha mudou em outra janela. Recarregue.',
  'campaign.master_only': () => 'Só o mestre pode fazer isso.',
  // Campaign set-up and master review.
  'campaign.preset_unavailable': () => 'Este preset não está disponível para campanhas.',
  'campaign.archived': () => 'A campanha está arquivada.',
  'invitation.not_found': () => 'Convite inválido, expirado ou esgotado.',
  'character.already_in_campaign': () => 'Este personagem já está na campanha.',
  'character.no_applied_sheet': () => 'Aplique ao menos uma proposta antes de ativar o personagem.',
  'character.active_proposal': () => 'Finalize a proposta em análise antes de ativar o personagem.',
  'change_request.not_active': () => 'Esta proposta já foi encerrada.',
  'change_request.review_version_conflict': () => 'A revisão foi alterada em outra janela. Os dados foram recarregados.',
  'change_request.stale': () => 'A ficha mudou desde a proposta. O jogador precisa atualizar o rascunho.',
  'decision.incomplete': () => 'Decida todas as operações antes de finalizar.',
  'decision.rejection_requires_individual_override': () => 'Uma rejeição só pode ser revertida individualmente.',
  'idempotency.key_required': () => 'Não foi possível concluir a operação.',
}

export function t(code: string, params: Params = {}): string {
  return (messages[code] ?? (() => 'Não foi possível concluir a operação.'))(params)
}

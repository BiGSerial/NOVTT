# SPEC-SPRINT-001: Campanhas, presets e fichas com revisão do mestre

**Status: ACCEPTED (requisitos de produto).** Implementação: PLANNED. Fonte: decisões explícitas do proprietário em 21/11/2026. Esta especificação substitui o status PROPOSED de SPEC-005 somente nos pontos aqui aprovados; conflitos com ADRs aceitos exigem resolução explícita antes de codificar.

## Limites do sprint
Implementar: (S001-01) domínio, migrations, policies e contratos; (02) campanhas e versões de preset fixadas; (03) convites e membros; (04) biblioteca pessoal de personagens, instâncias em campanha e NPCs; (05) rascunhos, propostas de alteração, revisão granular e aprovação parcial atômica; (06) UI acessível, testes e documentação. Não implementar sessões, rolagens oficiais, efeitos, movimento, IA, Android ou execução de mecânicas completas de livros.

## Regras obrigatórias
1. O backend Laravel é autoritativo; a engine em `packages/engine` permanece sem dependências de Laravel. Presets declarativos e imutáveis por versão, fixados à campanha; sem PHP/JS arbitrário.
2. A biblioteca pessoal e a instância de campanha são entidades distintas. Presets podem ser importados para catálogo canônico pela equipe após homologação, ou para biblioteca pessoal pelo usuário, sem status canônico. Referenciar versões imutáveis; não atualizar campanhas silenciosamente.
3. Campos têm autorização independente de leitura, edição e ações: `hidden`, `read_only`, `editable`, `approval_required`, `master_only`. Filtrar dados privados no servidor em todas as respostas. Mestre controla alterações permanentes em fichas aprovadas.
4. Um jogador cria rascunho e submete proposta. O mestre decide por **operação**, podendo aprovar várias operações da mesma seção de uma vez, mas rejeição exige motivo individual (texto simples, 10 a 1000 caracteres). Decisões provisórias são editáveis; finalização é imutável.
5. Operações tipadas: `set_field`, `unset_field`, `add_item`, `set_item_field`, `remove_item`, `reorder_items`; usar caminhos JSON Pointer e IDs estáveis. Validar alvo, precondição, schema, autorização, limites e dependências. Proibir substituição de subárvores para contornar políticas.
6. No máximo uma proposta ativa (`submitted`, `in_review`, `awaiting_resolution`) por personagem de campanha, com índice parcial único. Rascunhos são separados da ficha oficial. Usar `draft_version`, `review_version`, `sheet_revision` e chaves de idempotência.
7. Revisão agrupada por seções declaradas em `sheet.ui.json`, validada contra o schema da versão fixada. Aprovação em lote é apenas decisão provisória, nunca aplicação automática; não sobrescrever rejeições sem decisão individual. Finalização requer decisão explícita de todas as operações.
8. Validar **o conjunto aprovado inteiro** na engine, inclusive restrições cruzadas entre seções, antes de aplicar atomicamente em transação com lock/concurrency, revisão monotônica, snapshot canônico JSONB, snapshot histórico imutável, operações aprovadas, auditoria e outbox. Rejeição total não cria nova revisão.
9. Rebase assistido: comparação em três vias, conflitos tipados, prévia sem mutação e confirmação explícita do jogador; revalidar permissões/revisões no commit. Operações rejeitadas ficam no histórico e não retornam automaticamente.
10. Arquivamento preserva histórico; exclusão é operação distinta. Transferência de personagem e custódia temporária do mestre exigem autorização e auditoria. NPCs usam instâncias separadas, com cópias sanitizadas e isolamento de informações privadas.
11. Atribuição de pontos ao jogador **não** os distribui. A engine deve representar orçamento inicial, pontos bônus e XP como recursos distintos, com proveniência por incremento e custos dependentes da fase; a alteração de uma ficha aprovada exige concessão/permissão e revisão do mestre. Recursos temporários de sessão não são redistribuição permanente.
12. Uma versão publicada do preset é imutável; criar nova versão para correções. Validação possui três etapas: sintaxe JSON, contratos estruturais e compatibilidade real da engine.

## Contratos mínimos a formalizar no S001-01
`campaigns`, `campaign_members`, `campaign_invitations`, `preset_families`, `preset_definitions`, `preset_versions`, `user_preset_library`, `character_library`, `campaign_characters`, `character_drafts`, `change_requests`, `change_operations`, `operation_decisions`, `character_sheet_revisions`, `audit_events`, `outbox_events`, `npc_library`, `campaign_npcs`. Os nomes finais de tabelas podem variar, mas invariantes, índices, isolamento e trilha de auditoria não podem desaparecer.

Contratos JSON versionados: `novtt.sheet-ui/1`, `novtt.sheet-change-set/1`, `novtt.validation-result/1`; schema JSON Draft 2020-12. Endpoints versionados para submissão, revisão, aprovação em lote e finalização, com erros RFC 7807 e status 409/422 quando aplicável. Especificar OpenAPI antes de implementar o transporte.

## Dependências e validação
Distinguir dependências estruturais direcionadas (ordenáveis, sem ciclos) de restrições mecânicas n-árias. Reportar `rule_id`, `operation_ids`, `paths`, `severity`, `params`. Revalidar no servidor após qualquer alteração relevante. Nenhuma informação oculta pode vazar via erro, contagem, diff ou projeção.

## Critérios de aceite
- Usuário não autorizado não lê, altera, aprova ou descobre campos privados.
- Campanha fixa uma versão; upgrade não muda fichas existentes sem migração explícita.
- Rascunho, submissão, revisão e aplicação suportam concorrência, repetição de requests e falhas intermediárias.
- Aprovação parcial aplica o conjunto válido inteiro ou nada; operação rejeitada preserva justificativa e histórico.
- Bolinhas são inteiros com limites e custos por fase, mas engine suporta outros tipos de orçamento.
- Após aprovação, jogador não altera valores permanentes sem autorização; concessão de XP não faz gasto automático.
- UI por seções, teclado, leitor de tela, erros claros; testes de autorização, concorrência, invariantes, rollback, idempotência e isolamento.
- CI e testes reais devem passar antes de marcar IMPLEMENTED/VERIFIED.

## Fora do sprint, preservado no roadmap
Sessões, rolagens, efeitos temporários, valores derivados materializados, zonas e movimentos, conhecimento narrativo, IA e clientes Android. A arquitetura pode prever extensão, mas não implementar especulativamente.

## Política de trabalho
Seguir `AGENTS.md` e `docs/development/branching.md`. Commits pequenos, sem coautoria, PR Draft para `develop`, nunca merge automático. O agente deve primeiro inventariar código e ADRs, resolver conflitos normativos, criar testes e executar verificações. Esta especificação não declara o código pronto.

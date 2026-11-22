# Sprint 001 — Relatório de execução

Data: 2026-11-22
Escopo: [SPEC-SPRINT-001](../specs/SPEC-SPRINT-001-approved.md) e [SPEC-PRESET-AUTHORING-001](../specs/SPEC-PRESET-AUTHORING-001.md). Conflitos normativos registrados antes da implementação: [SPRINT-001-CONFLICTS](SPRINT-001-CONFLICTS.md).

Estado: **IMPLEMENTED**, não VERIFIED. Os PRs são Draft para `develop`, empilhados na ordem dos tickets, e aguardam revisão do dono.

## Tickets

| Ticket | PR | Entrega principal |
|---|---|---|
| S001-01 | #6 | Migrations PostgreSQL com invariantes no banco, engine (operações tipadas, JSON Pointer, acesso por campo, resultado de validação, porta estrutural), Sanctum, models estritos, policies, contratos JSON v1 e OpenAPI |
| S001-02 | #7 | Problem+json, idempotência transacional, auditoria/outbox, importação de presets em três etapas, publicação canônica só por console, campanhas fixadas em versão |
| S001-03 | #8 | Convites (token 256 bits, só hash), aceite com lock, membros, regra do último mestre, rate limit |
| S001-04 | #9 | Biblioteca pessoal, instâncias de campanha, projeção allow-list por campo, transferência, custódia, NPCs sanitizados |
| S001-05 | #10 | Motor de mudanças com orçamento e proveniência, rascunho, submissão, decisões, finalização atômica, rebase do rascunho, concessões, aprovação do personagem |
| S001-06 | #11 | UI acessível (PWA e painel do mestre), testes de acessibilidade, manual de presets, documentação |

## Testes executados (Docker, PostgreSQL 18, Node 24, PHP 8.5)

`sh scripts/dev.sh test` em 2026-11-22:

| Suite | Resultado |
|---|---|
| Engine (PHPUnit) | 67 testes, 373 asserções, OK |
| API (PHPUnit, PostgreSQL real) | 76 testes, 566 asserções, OK; `composer audit` limpo |
| PWA + painel do mestre (Vitest, jsdom, axe-core) | 21 testes, OK; `vue-tsc` limpo; build OK; `npm audit` 0 vulnerabilidades |
| OpenAPI (Redocly 2.76.0) | válido; teste de paridade rotas × OpenAPI |

## Critérios de aceite

| Critério | Evidência |
|---|---|
| Não autorizado não lê, altera, aprova nem descobre campos privados | `AuthorizationMatrixTest`, `CharacterApiTest`, `ReviewWorkflowApiTest::test_players_cannot_target_or_discover_private_fields`, `SheetProjectorTest`; 404 uniforme |
| Campanha fixa uma versão; upgrade não muda fichas | `CampaignApiTest::test_pinned_version_does_not_change_existing_characters`; FK `RESTRICT`; versões imutáveis por trigger |
| Concorrência, repetição e falhas intermediárias | `FinalizeConcurrencyTest` (segunda conexão), idempotência em todos os comandos, `test_failure_midway_rolls_everything_back` |
| Aprovação parcial: conjunto válido inteiro ou nada; rejeição preserva motivo e histórico | `test_full_review_with_partial_approval…`, `test_invalid_approved_subset_changes_nothing`, triggers append-only |
| Bolinhas inteiras com limites e custos por fase; outros orçamentos suportados | `SheetChangeEngineTest` (custos fixos e multiplicadores, bônus, XP, limites, dependências) |
| Após aprovação, sem alteração permanente sem concessão; XP não gasta sozinho | `test_after_approval_permanent_changes_need_granted_budget_and_review` |
| UI por seções, teclado, leitor de tela, erros claros | `sheet-editor.test.ts`, `review-panel.test.ts` (axe WCAG 2.1 A/AA no jsdom) |
| CI e testes reais passam | checks `api`, `engine`, `player-pwa`, `contracts`, `branch-policy` em cada PR |

## Decisões técnicas adotadas

- ADR 0009 (Sanctum) e ADR 0010 (camadas de validação, PostgreSQL obrigatório).
- Rebase movido da proposta para o rascunho: com uma proposta ativa por personagem, a defasagem ocorre no rascunho (OpenAPI atualizado).
- Cobrança de orçamento na ordem das operações: o JSONB não preserva a ordem das chaves.
- `@vue/test-utils` evitado (dependência transitiva vulnerável); componentes montados com `createApp` no jsdom.

## Pendências e riscos conhecidos

1. **Android** fora do sprint: capacidades de jogador não podem ser marcadas completas (ADR 0005, conflito C1).
2. **Fluxos de conta** (cadastro, login, recuperação) não implementados (ADR 0009); a UI depende de sessão existente.
3. **Acessibilidade manual**: contraste e leitores de tela reais (NVDA, VoiceOver, TalkBack) ainda não testados; axe no jsdom não cobre contraste.
4. **Outbox**: eventos gravados, mas não há publicador/consumidor (Reverb fora do sprint).
5. **Upgrade de versão de preset** da campanha (migração explícita) não implementado.
6. `phpredis` ausente na imagem PHP; Redis não é usado ainda.
7. Job `contracts` não está entre os checks obrigatórios da `main`.
8. Pacote Sangue-Suga V20 continua não homologado (C6).

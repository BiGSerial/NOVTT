# ADR-001: Engine universal de criação, uso e progressão de personagens

**Status:** proposta normativa para implementação.  
**Motivação:** suportar sistemas como D&D 5e (2014/2024), Vampiro V20 Dark Ages, V5 e regras autorais sem código exclusivo por jogo.

## 1. Princípio

A engine implementa **primitivas**; o preset define **semântica de jogo**. Não introduzir `DndEngine`, `VampireEngine`, `BrujahController` ou decisões por `system_id` no núcleo.

### Primitivas comuns

- **Schema de ficha:** campos typed (`integer`, `decimal`, `boolean`, `string`, `enum`, `array`, `object`, `resource`, `track`, `reference`), grupos, defaults, limites, visibilidade, edição, dependência, campos computados.
- **Criação:** fases e ordem, pools de pontos, prioridades por categoria, escolha de opções, pré-requisitos, restrições e revisão final.
- **Progressão:** transições com XP/pontos, compras por custo, avanço por nível, marcos, treinamentos, automações e híbridos; não assumir níveis.
- **Rolagens:** dados d4/d6/d8/d10/d12/d20/d100, expressões, pools, threshold, sucessos líquidos, cancelamentos, testes opostos, vantagem/desvantagem, críticos e regras específicas parametrizadas.
- **Efeitos:** fontes (traço, item, magia, estado), condições, durações, pilhas, prioridades, consumo, expiração e regras de conflito.
- **Inventário:** itens de catálogo e instâncias, proprietário, quantidade, equipado, peso, capacidade, transferência autorizada, proveniência.
- **Recursos:** pools como vida, sangue, mana e vontade, limites, gastos, recuperação e histórico.
- **Governança:** rascunho, submissão, validação, aprovação/rejeição pelo mestre, aplicação transacional, auditoria e rollback controlado.
- **Compêndio:** dados separados das regras, com fonte, licença, atribuição, versão, locale e status de publicação.

## 2. Identidade e versionamento

Manter separados:

- `family_id` (ex.: `dnd-5e`, `storyteller`): categoria técnica de conveniência, nunca branch de código.
- `preset_id` imutável (ex.: `dnd-5e-2014`, `dnd-5e-2024`, `vampire-dark-ages-v20`).
- `rules_revision` (2014, 2024, edição específica).
- `source_version` (ex.: SRD 5.1; SRD 5.2.1), `errata_reference` e data consultada.
- `preset_version` em SemVer (versão da codificação, não da edição).
- `character.preset_snapshot_id` e `session.preset_snapshot_id` para reprodutibilidade.

Nunca migrar fichas entre revisões implicitamente. Migração explícita, com dry run, relatório de diferenças, confirmação/aprovação e snapshot anterior.

## 3. Representação e execução

O preset é **JSON declarativo** validado por JSON Schema e validação semântica; regras complexas devem usar AST tipada com operações allowlist (`add`, `subtract`, `multiply`, `divide`, `floor`, `min`, `max`, `compare`, `and`, `or`, `not`, `if`, `lookup`, `sum`, `count`).

- Referências explícitas a caminhos do modelo. Nada de `eval`, código remoto ou scripts de preset.
- Limites de tamanho, profundidade, tempo e custo de avaliação.
- Grafo de dependências para campos computados, com detecção de ciclos.
- Avaliação determinística; rolagens usam gerador de aleatoriedade seguro no servidor, com registro de parâmetros e resultados.
- Regras disparadas por eventos (`character.created`, `trait.increase.requested`, `item.equipped`, `resource.spent`, `roll.requested`, `session.started`).
- Engine deve produzir `decision_trace`: regras, modificadores, fontes e resultados, para inspetor de cálculos.
- Presets **imutáveis após publicação**: atualizar cria nova versão.

## 4. Fluxo de criação

`draft -> choose_options -> allocate_points -> derive_values -> validate -> submit -> approve? -> activate`

- Validação imediata na interface é sugestiva; decisão final acontece no backend.
- Mestre controla regra de necessidade de aprovação conforme mesa, com override auditado.
- Campos derivados não são gravados como autoridade independente, podem ser materializados em cache com versão/hash de dependências.

## 5. Fluxo de progressão

`request -> quote_cost -> check_prerequisites -> check_balance -> submit -> gm_approval? -> atomic_commit -> audit_event`

- Não assumir aumento por nível. Suportar compras individuais, progressão por nível e marcos.
- Custos calculados pela regra vigente no snapshot do personagem; não pelo preset mais novo disponível.
- Use transação DB: débito + alteração + evento + snapshot ou rollback integral.
- Conflito concorrente resolvido por locking/versionamento otimista.

## 6. Exemplo A: D&D compatível com SRD

- Atributos numéricos, modificadores derivados; escolhas de origem/classe; progressão por nível/XP/marco; benefícios e maestrias condicionados à revisão.
- `dnd-5e-2014` e `dnd-5e-2024` como identidades separadas.
- Usar apenas conteúdo do SRD efetivamente licenciado na distribuição; SRD 5.2.1 não é sinônimo do Livro do Jogador de 2024 completo.

## 7. Exemplo B: pool d10 e progressão por compra

- Ficha com atributos de escala discreta, habilidades, recursos, poderes e compras de características.
- Reserva `atributo + habilidade`, dados d10, contagem de resultados contra dificuldade, cancelamentos quando previstos pela revisão.
- Evolução: valor atual, fator de custo, pré-requisitos, saldo, aprovação, registro.
- Exemplos técnicos **sem nomes ou descrições proprietárias** são suficientes para demonstrar a generalidade da engine.

## 8. Permissões e proteção de dados

- Papéis: proprietário do personagem, mestre da mesa, co-mestre delegado, jogador, visitante.
- Separar `owner`, `creator`, `campaign_membership`, `editable_fields`, `approval_policy`.
- Mestre não deve acessar segredos privados fora das permissões definidas; jogador não pode alterar silenciosamente ficha vinculada a sessão.
- Histórico append-only para operações críticas. Auditoria de quem, quando, motivo, versão da regra, valores anterior/posterior.
- Ficha sempre exportável em formato neutro. Cancelamento de assinatura não bloqueia dados básicos.

## 9. Importação de regras e segurança de conteúdo

`source -> rights_review -> extraction -> normalization -> AST -> schema_validation -> semantic_tests -> human_review -> signed_publish`

- IA pode auxiliar na extração, **não é autoridade**. Cada regra deve ter referência de origem e estado de revisão.
- Proibir publicação de texto não licenciado e de nomes/arte protegidos sem autorização.
- Presets comunitários passam por moderação e análise de direitos.
- Compêndio pode ter licença diferente de preset; não misturá-los por conveniência.

## 10. Não objetivos iniciais

- Reproduzir integralmente os livros de terceiros.
- Fazer combate videogame automatizado ou simulação física obrigatória.
- Integração não autorizada com dados privados do D&D Beyond.
- Publicação de pacotes proprietários de Vampiro durante o sprint de fundação.

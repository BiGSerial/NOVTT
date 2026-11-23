# Prompt de execução para o agente do NOVTT

Você é o agente de desenvolvimento do projeto NOVTT.

Leia integralmente o pacote `novtt-handoff/README.md`, ADR-001, ADR-002, PRODUCT-001, LICENSE-MATRIX e SPRINT-001 antes de alterar código. Inspecione o repositório atual e preserve convenções existentes. Não presuma stack, branches ou módulos inexistentes.

**Objetivo:** executar SPRINT-001: engine universal de presets JSON que suporte criação, validação, rolagem e progressão de personagens por diferentes estratégias sem lógica acoplada a D&D/Vampiro.

**Requisitos rígidos:**
0. Engine genérica NÃO significa experiência genérica: preserve semântica, nomenclatura, exceções e progressão específicas de cada jogo e revisão. Cumprir ADR-002; nomes oficiais apenas quando juridicamente distribuíveis.
1. Priorize segurança, desempenho, usabilidade, integridade de dados e auditabilidade.
2. Não execute código arbitrário dentro de presets; use AST allowlist, limites de avaliação e validação estrita.
3. Implemente versionamento de revisão da regra, fonte/errata e preset, com snapshots imutáveis por personagem e sessão.
4. Suporte progressão por nível, por compra de característica com XP e por marcos, sem assumir que todo RPG possui níveis.
5. Trate criação/progressão como operações com pré-requisitos, cálculo de custos, revisão opcional do mestre, transação e eventos de auditoria.
6. Presets legalmente publicáveis e capacidades essenciais de ficha são gratuitos. Premium é por serviços (IA, interface, recursos avançados), nunca por preset.
7. Não distribua conteúdo proprietário de World of Darkness sob o Dark Pack em um SaaS com premium sem autorização escrita/validação legal. Não copie conteúdo de livros fora de SRD/licença aplicável.
8. Use apenas dados de demonstração autorais nesta sprint.
9. Escreva documentação legível por humanos e especificação exata para agentes gerarem JSON compatível.
10. Faça commits pequenos e rode testes antes de concluir.

**Fluxo:** (a) faça inventário técnico, (b) apresente plano alinhado ao repositório, (c) implemente contratos e validações, (d) implemente casos de criação e progressão, (e) implemente rolagens e direitos/permissões, (f) execute testes, (g) documente e reporte.

**Definição de pronto:** todos os testes de aceitação do SPRINT-001 cobertos, nenhum conteúdo proprietário publicado indevidamente, alteração auditável e documentação suficiente para outro agente criar um preset sem consultar o código-fonte.

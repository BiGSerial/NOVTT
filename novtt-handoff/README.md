# NOVTT | Pacote de especificação para agente

**Estado:** handoff de arquitetura e requisitos, não implementação.  
**Data de consolidação:** 2026-11-23.  
**Objetivo:** orientar implementação de engine universal de fichas, criação, progressão e mecânicas de RPG sem acoplar a aplicação a uma editora ou sistema.

## Leia nesta ordem

1. `docs/architecture/ADR-001-universal-rules-engine.md`: decisões e contratos arquiteturais.
2. `docs/architecture/ADR-002-ruleset-fidelity-and-official-taxonomy.md`: fidelidade mecânica, nomes oficiais condicionados aos direitos, compêndios e testes de conformidade.
3. `docs/product/PRODUCT-001-access-and-monetization.md`: produto, plano único e gratuidade dos presets.
4. `docs/legal/LICENSE-MATRIX.md`: limites por licença, bloqueadores e fontes oficiais.
5. `docs/agent/SPRINT-001-IMPLEMENTATION.md`: tarefas e testes de aceitação para o agente.
6. `docs/agent/AGENT-PROMPT.md`: prompt para execução.
7. `schemas/preset-manifest.schema.json` e `presets/examples/*.json`: contrato mínimo de manifesto e exemplos **não executáveis**.

## Decisões confirmadas pelo produto

- O NOVTT é uma plataforma universal de RPG, não um sistema dedicado a D&D ou Vampiro.
- **Fidelidade máxima por preset:** a engine genérica deve executar e exibir as mecânicas, classificações, opções, relações e progressões próprias de cada edição, com nomenclatura original quando legalmente autorizada. Vide ADR-002.
- Presets **legalmente distribuíveis** e suas mecânicas essenciais devem ser de acesso livre, sem compra por preset.
- A monetização desejada é por **plano único** para serviços avançados (IA, interface visual, armazenamento e similares), não por venda de regras.
- Criação, progressão e consulta das próprias fichas devem funcionar sem assinatura.
- Mestre controla permissões/alterações de ficha da campanha; operações são validadas e auditadas.
- Ano/revisão do sistema, versão de fonte/errata e versão técnica do preset são identificadores distintos.
- O agente deve privilegiar segurança, desempenho, usabilidade, testes e documentação para humanos e IA.

## Questões NÃO resolvidas / proibições

- **Não publicar automaticamente conteúdo proprietário de World of Darkness** sob assinatura com base apenas no Dark Pack. Obter autorização específica ou parecer jurídico antes da publicação.
- **Não atribuir CC BY 4.0 ao Livro do Jogador ou Guia do Mestre integrais**: a licença se aplica aos SRDs cobertos, não a todo o material comercial.
- **Não copiar marcas, logotipos, artes ou compêndios fora de licença**.
- Não executar PHP/JavaScript arbitrário fornecido por presets.
- Não confundir exemplos de JSON deste pacote com o contrato integral de execução de regras.

## Entrega esperada

Implementar os contratos universais mínimos e um preset de demonstração autoral que prove criação e progressão. Preparar pipelines de importação e publicação licenciada para SRD de D&D. Vampiro deve servir como cenário de testes abstratos (reservas de d10 e progressão por compra), mas a distribuição de nomes/conteúdo proprietário deve permanecer bloqueada até liberação.

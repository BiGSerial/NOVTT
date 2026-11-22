# SPEC-PRESET-AUTHORING-001: Família, variantes e importação

**Status: ACCEPTED (requisitos).** Implementação: PLANNED. O contrato abaixo é **proposta de formato**, não evidência de que a engine atual já o aceite.

## Objetivo
Permitir que humanos e agentes de IA construam presets declarativos compatíveis com a engine, sem conteúdo protegido de terceiros. Uma família agrupa variantes independentes (`sangue-suga-v20`, `sangue-suga-da-v20`, `sangue-suga-v5`). Uma variante tem versões imutáveis; a campanha fixa uma versão.

## Pacote candidato de referência
O pacote `sangue-suga-v20@1.0.0` foi gerado como **candidato externo**, não importado, homologado ou testado contra o repositório. Contém `manifest.json`, `catalog.json`, `sheet.schema.json`, `sheet.ui.json`, `rules.json`, `examples/character-draft.json` e `README.md`. Os contratos `novtt.preset-manifest/1`, `novtt.catalog/1`, `novtt.sheet-ui/1`, `novtt.rules/1` são **designs a formalizar**. Não publicar sem adaptador, schemas oficiais e testes.

## Guia humano: processo de autoria
1. Escolher `family_id`, `variant_id`, versão semântica e licença/proveniência. Usar nomes e visuais próprios.
2. Declarar campos canônicos no JSON Schema 2020-12: texto, inteiros, números, booleanos, coleções com IDs estáveis, valores mínimos/máximos e defaults.
3. Declarar UI separadamente: seções, grupos, caminhos JSON Pointer, widgets acessíveis como `dot_rating`. Cada caminho deve existir no schema.
4. Declarar regras sem código executável: fases de criação, prioridades, orçamentos, custos de bônus e XP, elegibilidade, pré-requisitos e cálculos derivados quando a engine suportar.
5. Distinguir `permanent_rating` de recursos atuais e de modificadores; não misturar pontos iniciais, bônus e XP. Registrar proveniência de cada incremento.
6. Incluir exemplos válidos e inválidos, instruções de uso, limitações e testes de autorização.
7. Executar validação sintática, estrutural, semântica e de compatibilidade com as capacidades reais da engine.
8. Publicar no catálogo canônico **somente pela equipe**, após homologação; importação pessoal não recebe selo canônico.

## Instruções normativas para IA
- MUST ler `AGENTS.md`, `docs/specs/SPEC-SPRINT-001-approved.md`, ADRs, contratos existentes e implementações da engine.
- MUST NOT inventar operadores, campos de contrato ou capacidades da engine. Se faltar suporte, registrar `UNSUPPORTED_CAPABILITY` e propor contrato/versionamento separado.
- MUST NOT copiar texto longo, ilustrações, layout protegido, histórias ou tabelas narrativas de livros de terceiros.
- MUST criar JSON determinístico, sem código arbitrário, referências externas inseguras, ciclos em dependências estruturais ou vazamento de dados privados.
- MUST gerar testes positivos e negativos para cada orçamento, fase, pré-requisito, custo, permissão, limite e conflito.
- MUST NOT considerar JSON Schema válido equivalente a compatibilidade da engine.
- MUST registrar versão exata do preset, engine, contrato e resultado da validação.
- SHOULD evitar duplicação entre variantes, mas MUST NOT herdar regras de uma edição diferente sem validação específica.

## Importação e status
`draft` → `structurally_valid` → `engine_compatible` → `reviewed` → `published`. A equipe controla publicação canônica; usuários podem manter versões pessoais não verificadas, sujeitas à mesma segurança. Importação é transacional, limitada por tamanho/complexidade, com relatório de erros estruturado e sem executar scripts.

## Política de pontuação
Bolinhas são UI de valores inteiros, não modelo financeiro. A engine precisa aceitar moedas e custos arbitrários por sistema: pontos de prioridade, bônus, XP, custos fixos, custos pelo nível atual, limites por fase e regras condicionais. Alterar bolinha em rascunho deve devolver ao **orçamento de origem**; depois de aprovação, somente proposta autorizada. Mestre concede orçamento; jogador escolhe alocação; validação e revisão são server-side.

## Pendências antes de homologar Sangue-Suga V20
- Conferir catálogo de habilidades, clãs, disciplinas, antecedentes, geração e capacidades de sangue contra fonte licenciada.
- Confirmar regras e exceções de criação, moralidade alternativa, progressão e personagens não vampiros.
- Construir schemas e validador oficiais, contratos da engine e testes de importação.
- Não tratar V20 moderno como Idade das Trevas V20.

## Controle de escopo
Sprint 001: catálogo/versionamento, pinning, estrutura declarativa básica, importação segura se necessária ao fluxo de campanhas, autorização e revisão de fichas. Ferramenta visual de autoria, marketplace, execução de todas as regras de livros e publicação pública completa são roadmap, salvo autorização explícita.

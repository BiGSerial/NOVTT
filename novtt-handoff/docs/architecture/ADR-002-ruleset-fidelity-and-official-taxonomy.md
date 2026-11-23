# ADR-002: Fidelidade mecânica e taxonomia oficial por sistema, edição e revisão

**Status:** requisito obrigatório de produto e arquitetura. **Data:** 2026-11-23.

## Decisão

A engine é universal por implementação, mas o **preset deve reproduzir com alta fidelidade as mecânicas e a taxonomia da fonte específica** (sistema, edição, revisão, errata e eventualmente suplementos autorizados). "Universal" NÃO significa simplificar, normalizar à força, renomear conceitos de domínio na interface, ou perder peculiaridades do jogo.

A experiência deve parecer nativa: D&D mostra seus atributos, espécies/raças, classes, subclasses, magias, perícias, proficiências, ações e progressão; Vampire, conforme edição, mostra clãs, Disciplinas, geração/potência de sangue, virtudes, estradas/humanidade, antecedentes e pools de d10. **Esses são exemplos de requisitos de modelagem e UX, não autorização para publicar termos/textos proprietários.**

## Separar quatro camadas

1. **Core de primitivas:** tipos, relações, eventos, fórmulas, rolagens, efeitos, recursos, autorização, validação e histórico. Sem `if system == dnd`.
2. **Ruleset / preset de mecânicas:** semântica fiel à edição, incluindo exceções e precedência de regras, fluxos de criação, progressão, combate, testes, custos, limites, escolhas dependentes, triggers e duração.
3. **Compêndio / taxonomia:** registros versionados de espécies, raças, clãs, classes, subclasses, habilidades, Disciplinas, magias, itens, monstros, criaturas, condições e propriedades, com IDs estáveis, relação com regras e nomes localizados autorizados.
4. **Camada de apresentação:** rótulos, seções, campos de ficha e interface temática por preset sem duplicar lógica de negócio. Pode oferecer layouts diferentes por sistema com um renderer de componentes genéricos e extensões declarativas.

## Capacidade e fidelidade exigidas

- **Criação:** escolhas exclusivas/condicionais, custos, prioridades de grupos, pré-requisitos, escalas, limites, defaults, recursos iniciais, campos derivados, opções alternativas e aprovação do mestre.
- **Progressão:** XP e níveis, compra por pontos, custo relativo ao valor atual/novo conforme fonte, marcos, treinamento, múltiplas trilhas, upgrades com dependências, efeitos retroativos e validação de transições.
- **Mecânica de jogo:** dados variados, reservas, sucesso por limiar, cancelamentos, críticos/falhas críticas, disputas, resistências, modificadores circunstanciais, vantagem/desvantagem, gasto e recuperação, efeitos persistentes, prioridade/empilhamento e exceções com rastreio.
- **Compêndio:** busca e seleção de categorias nomeadas na edição, associação direta à ficha, atributos e ações mecânicas de criaturas/monstros, importação/exportação e versões por edição. **Não prometer catálogo completo onde a licença não permitir.**
- **Termos originais:** manter `canonical_id` próprio e `source_label`, com tradução/localização e aliases somente quando permitidos. Nunca substituir clã por classe ou Disciplina por magia no modelo semântico de exibição.
- **Proveniência:** toda regra/opção deve referenciar `source_id`, `edition`, `rules_revision`, `errata`, `license_id`, `rights_status`, `locale`, `review_status` e idealmente localização na fonte. Valores incertos são `unverified`, nunca tratados como canônicos.
- **Extensões:** plugins declarativos de operadores e estratégias gerais podem ser adicionados de modo seguro quando a expressividade atual não bastar, sem introduzir condicionais específicas de produto no core.

## Integridade de regras

Uma mecânica não está implementada apenas por existir um campo na ficha. A cobertura requer: (a) representação, (b) cálculo, (c) validação, (d) uso em ação/rolagem, (e) progressão, (f) testes, (g) explicação/trilha de decisão, conforme aplicável. Regras com exceções devem ser testadas explicitamente. Cálculo é autoritativo no servidor; apresentação e prévia no cliente não autorizam alterações.

## Qualidade e testes de conformidade

Cada preset deve publicar `conformance_suite` com casos para:
- criação inicial válida/inválida por combinação de opções;
- benefícios de espécies/raças/classes/clãs/subclasses e pré-requisitos (quando permitidos);
- progressão, custos, desbloqueios, limites e migrações;
- ataques, testes, recursos, efeitos e cancelamentos;
- peculiaridades/erratas da revisão;
- round-trip de importação/exportação sem perda de dados;
- isolamento: carregar dois presets de revisões diferentes na mesma instalação não contamina comportamento.

Níveis de cobertura: `schema_only`, `character_creation`, `progression`, `playable_core`, `full_licensed_scope`. Não exibir selo `full` sem cobertura e direitos auditados. Exibir limites do conteúdo de fonte claramente ao mestre.

## Política de nomes e licença

**Capacidade técnica não é licença de distribuição.** A engine aceita as categorias oficiais no modelo, porém nomes específicos, textos, artes e conteúdo proprietários somente serão distribuídos se a licença aplicável permitir ou houver autorização. D&D SRD: usar apenas conteúdo efetivamente coberto com atribuição. World of Darkness/Vampire: Dark Pack não deve ser assumido como licença de distribuição de conteúdo proprietário em SaaS monetizado, mesmo com preset gratuito. Sem liberação, usar demonstração autoral ou importação de dados próprios do usuário dentro de limites jurídicos avaliados. A existência de um nome isolado não implica que todo o catálogo ou texto narrativo seja livre.

Nunca declarar que uma opção é "oficial" se não provier de fonte oficial verificada. Preferir `source_authenticity: official | licensed | community | original`, que é distinto de `distribution_rights: allowed | restricted | pending_review`.

## Modelo orientativo (não schema final)

```json
{
  "canonical_id": "trait.example",
  "kind": "class",
  "system_id": "example-system",
  "edition": "example-edition",
  "rules_revision": "example-revision",
  "labels": { "pt-BR": "Classe de exemplo" },
  "source": {
    "source_id": "example-authorized-source",
    "source_authenticity": "original",
    "rights_status": "allowed",
    "license_id": "original-content",
    "review_status": "verified"
  },
  "mechanical_refs": ["rule.class.example.progression"]
}
```

## Critérios de aceite do agente

1. Dois sistemas com taxonomias radicalmente diferentes são renderizados sem hardcode de nomes no core.
2. Um preset pode definir classes/subclasses e outro clãs/Disciplinas sem perda semântica.
3. O agente prova um cálculo e uma progressão end-to-end por pelo menos duas estratégias diferentes.
4. Uma opção de compêndio sem direitos de distribuição não pode ser publicada por engano.
5. O usuário vê nome, comportamento, fontes, versão e limitações do preset escolhido.
6. Toda característica adicionada à ficha tem origem, restrições e efeitos auditáveis.

**Fora de escopo por padrão:** prometer 100% do conteúdo de livros comerciais, copiar descrições protegidas, usar marca/logotipo sem permissão, ou afirmar correspondência canônica sem testes e fonte verificável.

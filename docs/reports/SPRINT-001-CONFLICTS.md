# Sprint 001 — Conflitos normativos e lacunas

Data: 2026-11-22
Referências: [SPEC-SPRINT-001](../specs/SPEC-SPRINT-001-approved.md), [SPEC-PRESET-AUTHORING-001](../specs/SPEC-PRESET-AUTHORING-001.md), ADRs 0001–0008.

Registro feito **antes** da implementação, conforme a política da sprint. Cada item diz como a parte afetada será tratada.

## C1 — Paridade Android/PWA (ADR 0005, aceito) × Android fora do sprint

- ADR 0005: "A player feature is not complete until implemented and tested on both Android and PWA."
- SPEC-SPRINT-001: Android fora do sprint; S001-06 exige UI acessível.
- Tratamento: a UI de jogador da Sprint 001 é entregue apenas no PWA. Na matriz de paridade, as capacidades ficam `IMPLEMENTED (PWA)` / `PLANNED (Android)` e **não** podem ser marcadas VERIFIED nem "completas" até o Android existir. Contratos HTTP são os mesmos para os dois clientes. Nenhuma alteração do ADR 0005 é feita sem decisão do dono.

## C2 — Autenticação sem ADR

- MVP exige autenticação segura; SPEC-SPRINT-001 exige autorização por usuário, mas nenhum ADR define o mecanismo. O ADR de autenticação previsto na Sprint 0 (S0-04) não foi escrito.
- Tratamento: [ADR 0009](../adr/0009-api-authentication.md) registra a decisão técnica (Laravel Sanctum). Fluxos de cadastro, login e recuperação de conta **não** entram na Sprint 001 (escopo congelado); a sprint usa apenas a identidade autenticada para policies e endpoints.

## C3 — ADR 0008 (proposto) × SPEC-SPRINT-001 (aceita)

- ADR 0008 e SPEC-005 seguem PROPOSED. SPEC-SPRINT-001 aceita parte do modelo (versões imutáveis, pinning, autoridade do mestre).
- Divergências: ADR 0008 propõe "campaign patch" (mestre altera estrutura) e nomeia `family`/`edition`; SPEC-PRESET-AUTHORING-001 usa `family_id`/`variant_id`/versão semântica. Campaign patch não aparece no escopo da Sprint 001.
- Tratamento: seguir a terminologia aceita (`family`, `variant`, `version`). Campaign patch **não** é implementado. O scaffold `presets/sangue-suga/v20` não é alterado nem publicado (pendente de homologação).

## C4 — "Validar o conjunto aprovado na engine" × engine sem dependências

- SPEC-SPRINT-001 regra 8 exige validação na engine; a engine é PHP puro (`require` só `php`, teste `FrameworkIndependenceTest`) e não tem validador JSON Schema.
- Tratamento: [ADR 0010](../adr/0010-sheet-change-validation-layers.md). A engine orquestra a validação e define a porta `StructuralValidator`; a API implementa a porta com uma biblioteca JSON Schema 2020-12. Regras mecânicas (limites, orçamentos, dependências) são implementadas na engine sem dependências externas.

## C5 — Formatos `novtt.*` ainda são designs

- SPEC-PRESET-AUTHORING-001: `novtt.preset-manifest/1`, `novtt.catalog/1`, `novtt.sheet-ui/1`, `novtt.rules/1` são "designs a formalizar"; proíbe inventar capacidades da engine.
- Tratamento: S001-01 formaliza apenas o necessário ao fluxo da sprint (`sheet-ui/1`, `sheet-change-set/1`, `validation-result/1`, `preset-manifest/1` e o subconjunto de `rules/1` que a engine passa a suportar: orçamentos, custos e limites). Qualquer outro recurso é rejeitado com `UNSUPPORTED_CAPABILITY`. `novtt.catalog/1` fica fora.

## C6 — Pacote candidato `sangue-suga-v20@1.0.0`

- Não está no repositório e não foi homologado.
- Tratamento: os testes usam um preset fictício neutro, criado no repositório como fixture. Nenhum conteúdo de terceiros.

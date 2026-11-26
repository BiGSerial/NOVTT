# Manual de autoria de presets (contratos v1 e v2)

Para humanos e agentes de IA. Fontes normativas: [ADR 0012](../adr/0012-universal-preset-engine.md), [SPEC-PRESET-ENGINE-001](../specs/SPEC-PRESET-ENGINE-001.md), [SPEC-PRESET-AUTHORING-001](../specs/SPEC-PRESET-AUTHORING-001.md), [ADR 0010](../adr/0010-sheet-change-validation-layers.md).

Exemplos completos e testados:
- [`presets/novtt-demo/demo-linhagens/1.0.0`](../../presets/novtt-demo/demo-linhagens/1.0.0): reserva de d10, prioridades 7/5/3, compra por XP, linhagens e dons, itens com efeitos (contratos v2).
- [`presets/novtt-demo/demo-heroico/1.0.0`](../../presets/novtt-demo/demo-heroico/1.0.0): d20 com modificadores, níveis por XP ou marcos, vocações e especializações (contratos v2).
- [`presets/fixtures/demo-basic/1.0.0`](../../presets/fixtures/demo-basic/1.0.0): o mínimo com `rules/1`.
- [`presets/novtt-demo/invalid-examples.json`](../../presets/novtt-demo/invalid-examples.json): variações inválidas, cada uma com o `rule_id` esperado.

Este manual descreve **apenas** o que a engine `0.2.0` executa. Qualquer recurso fora daqui é rejeitado com `UNSUPPORTED_CAPABILITY`. JSON Schema válido não equivale a compatibilidade com a engine; a terceira etapa da importação decide.

## 1. Pacote

Uma versão imutável de uma variante. Partes enviadas juntas em `POST /api/v1/presets/imports` (máx. 512 KiB):

| Parte | Contrato | Arquivo | Obrigatória |
|---|---|---|---|
| `manifest` | `novtt.preset-manifest/2` (ou `/1`, legado) | `manifest.json` | sim |
| `sheet_schema` | JSON Schema Draft 2020-12, subconjunto da seção 3 | `sheet.schema.json` | sim |
| `sheet_ui` | `novtt.sheet-ui/1` | `sheet.ui.json` | sim |
| `rules` | `novtt.rules/2` (ou `/1`) | `rules.json` | sim |
| `compendium` | `novtt.compendium/1` | `compendium.json` | só se o manifesto declarar `files.compendium` |

Contratos em `contracts/presets/`. Correções nunca alteram uma versão: crie `version` nova (SemVer). Reenviar o mesmo conteúdo é idempotente; conteúdo diferente com a mesma versão dá `409 preset.version_exists`.

## 2. Manifesto (`novtt.preset-manifest/2`)

Três identificadores diferentes, nunca misture:

| Campo | Significa | Exemplo |
|---|---|---|
| `version` | versão técnica desta codificação (SemVer) | `1.2.0` |
| `rules_revision` | edição/revisão do jogo representado | `2024`, `original-2026` |
| `source_version`, `errata_reference` | documento e errata consultados | `SRD 5.2.1`, `null` |

Demais campos:
- `family_id`, `variant_id`: slugs próprios. A família é só agrupamento de catálogo; nunca muda comportamento.
- `license`: `identifier` (ex. `CC-BY-4.0`, `NOVTT-original`), `source_url`, `rights_status` (`draft`, `rights_review`, `approved`, `quarantined`, `published`, `retired`), `consulted_at` (data em que a licença foi lida), `attribution` (texto **exato** exigido pela licença), `requires_attribution` (padrão: verdadeiro para `CC-BY*`).
- `provenance.source_authenticity`: `official`, `licensed`, `community` ou `original`. Isso é **diferente** de direito de distribuição: algo oficial pode não ser distribuível.
- `capabilities`: booleanos (`character_creation`, `character_progression`, `dice_resolution` obrigatórios).
- `coverage`: `schema_only`, `character_creation`, `progression`, `playable_core` ou `full_licensed_scope`. Não declare `full_licensed_scope` sem cobertura testada e direitos auditados.
- `limitations`: frases curtas mostradas ao mestre.
- `publication.public: true` exige `rights_status` `approved` ou `published`.

## 3. Ficha (`sheet_schema`)

- Raiz `type: object`. Declare cada campo explicitamente em `properties`.
- Palavras-chave aceitas: `type`, `properties`, `additionalProperties`, `required`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `enum`, `const`, `default`, `items`, `minItems`, `maxItems`, `maxProperties`, `propertyNames`, `format`, `title`, `description`, `$schema`. Qualquer outra (inclusive `$ref`, `allOf`, `if`) dá `UNSUPPORTED_CAPABILITY`.
- Propriedades iniciadas por `_` são reservadas à engine.
- **Coleções**: array de objetos com `id` string obrigatório.
- Valor ausente vale `default`, senão `minimum`, senão 0 (números), `''` (texto), `false`.
- Campos derivados (seção 6.3) também são declarados aqui, normalmente sem `default`.

## 4. Interface e acesso (`sheet_ui`)

Seções com elementos `field` ou `collection`. Acesso efetivo = do elemento, senão da seção, senão `default_access`. Caminho não declarado = `master_only`.

| Acesso | Jogador lê | Jogador propõe |
|---|---|---|
| `editable` | sim | sim |
| `approval_required` | sim | sim (sempre revisado) |
| `read_only` | sim | não |
| `hidden` | não | não |
| `master_only` | não | não |

Em fichas de campanha toda mudança permanente passa pela revisão do mestre. Widgets: `text`, `textarea`, `integer`, `number`, `boolean`, `dot_rating` (exige `integer`), `select`. Os rótulos da interface são do preset: um preset mostra "Vocação", outro "Linhagem"; a engine não conhece nenhum desses nomes.

## 5. Regras v1 (também válidas em v2)

- **Orçamentos** (`budgets`): `initial`, `bonus`, `experience`, `other`, com `initial_amount`. Concessões do mestre somam saldo e nunca gastam.
- **Custos** (`costs`): por fase (`creation` até a aprovação do personagem, `advancement` depois), orçamento, alvos (`/caminho` ou `/pai/*`) e modelo `fixed_per_dot`, `current_rating_multiplier` (com `when_zero`), `new_rating_multiplier`. Para cada ponto, a primeira regra da fase cujo orçamento cobre o custo paga. `free_dots` marca pontos gratuitos.
- **Limites por fase** (`phase_limits`) e **dependências** (`dependencies`, sem ciclos).
- Proveniência: cada ponto comprado registra o orçamento que pagou; baixar devolve ao orçamento de origem.

## 6. Regras v2 (`novtt.rules/2`)

### 6.1 Fórmulas

Toda regra calculada é uma árvore JSON. Nada de texto executável.

| Nó | Forma | Observação |
|---|---|---|
| constante | `{"const": 3}` | número, texto (≤ 200), booleano ou `null` |
| campo | `{"ref": "/attributes/physical/vigor"}` | valor ausente usa o default do schema |
| derivado | `{"derived": "vitality"}` | valor já com efeitos |
| orçamento | `{"budget": "xp", "of": "granted"}` | `amount` (inicial), `granted` (concedido), `spent`, `balance` |
| variável | `{"var": "current"}` | só onde o contexto declara (custos: `current`, `new`; rolagens: nomes das entradas) |
| campo do item | `{"item": "weight"}` | só dentro de `sum`/`count` ou de efeito por coleção |
| aritmética | `{"op": "add", "args": [a, b, ...]}` | também `subtract`, `multiply`, `min`, `max` (2 a 32 args) |
| divisão | `{"op": "divide", "args": [a, b]}` | inteira quando exata; divisão por zero é erro |
| piso | `{"op": "floor", "args": [a]}` | |
| lógica | `and`, `or` (2+), `not` (1) | exigem booleanos |
| comparação | `{"op": "compare", "cmp": "ge", "args": [a, b]}` | `eq`, `ne`, `lt`, `le`, `gt`, `ge`; `"1"` ≠ `1` |
| condição | `{"op": "if", "args": [cond, então, senão]}` | |
| tabela | `{"op": "lookup", "key": n, "table": {"primary": 7}, "default": n}` | chave exata |
| degraus | `{"op": "lookup", "key": n, "steps": [[0, 1], [300, 2]]}` | maior limiar ≤ chave; limiares crescentes |
| soma | `{"op": "sum", "collection": "/inventory", "value": n, "where": n}` | |
| contagem | `{"op": "count", "collection": "/inventory", "where": n}` | |

Limites: profundidade 24, 256 nós por fórmula, 50 000 passos por avaliação, |valor| ≤ 10⁹, tabelas com até 128 entradas.

Exemplo, modificador `piso((capacidade − 10) / 2)`:

```json
{"op": "floor", "args": [{"op": "divide", "args": [{"op": "subtract", "args": [{"ref": "/abilities/forca"}, {"const": 10}]}, {"const": 2}]}]}
```

### 6.2 Orçamentos por fórmula e custo por fórmula

- `budgets[].amount`: fórmula do valor inicial; pode ler a ficha (prioridade escolhida, nível), **não** orçamentos nem derivados. Ex.: prioridades 7/5/3:
  `{"op": "lookup", "key": {"ref": "/priorities/physical"}, "table": {"primary": 7, "secondary": 5, "tertiary": 3}, "default": {"const": 0}}`.
- Modelo de custo `{"type": "formula", "formula": ...}` com `current` (valor antes do ponto) e `new`. Custo negativo é erro.
- Se uma mudança reduzir um orçamento abaixo do já gasto: `budget.overdrawn`.

### 6.3 Valores derivados (`derived`)

`{"id", "path", "formula", "label"}`. O caminho precisa existir no schema e ser escalar. Ninguém escreve nele (`op.derived_read_only`, inclusive o mestre). O valor é recalculado a cada leitura e nunca gravado. Derivados e efeitos não podem formar ciclo (`rules.derived_cycle`).

### 6.4 Efeitos (`effects`)

Modificam um derivado (`target`).
- `mode: "add"` (padrão): soma `value`. Efeitos com o mesmo `stack_group` acumulam por `stacking`: `sum` (todos) ou `highest` (só o maior).
- `mode: "set"`: substitui o resultado; vence a maior `priority`, e no empate o primeiro declarado.
- `when`: fórmula booleana (efeito de regra, ex. um dom escolhido).
- `source: {"collection": "/inventory", "where": {"item": "equipped"}}`: uma instância por item que satisfaz `where`; `value` pode ler `item`.
- `duration`: metadado (`permanent`, `scene`, `session`, `rounds`, `manual`). A expiração automática ainda não existe.

Cada leitura gera um `decision_trace`: valor base, cada efeito, origem (regra ou item) e se foi aplicado.

### 6.5 Restrições (`constraints`)

`{"id", "phase": "creation|advancement|always", "assert": fórmula booleana, "paths": [...]}`. Avaliadas sobre a ficha candidata inteira. Falha: `constraint.failed` com `params.rule`.

### 6.6 Criação (`creation`)

- `steps`: ordem das etapas (ex. `identity → origin → attributes → review`). Cada uma tem `paths` (o que pertence a ela), `required` (precisa estar preenchido) e `complete_when` (fórmula). Uma etapa está completa quando não falta nada, a condição vale e nenhum erro toca seus caminhos. A ficha só é ativada pelo mestre quando **todas** estão completas (`character.creation_incomplete`).
- `choices`: `{"id", "path", "options": [{"value", "label", "requires", "compendium_id"}]}`. O valor precisa ser uma opção (`choice.unknown_option`) e o pré-requisito precisa valer (`choice.prerequisite_unmet`), inclusive se outra mudança posterior o quebrar.

### 6.7 Progressão por nível ou marco (`progression.tracks`)

`{"id", "path": "/progress/level", "measure": fórmula, "thresholds": [0, 300, 900], "max_step": 1}`. `thresholds[i]` é a medida para o nível `i + 1`. Medida típica: `{"budget": "xp", "of": "granted"}` (XP não gasto) ou marcos concedidos, ou o maior dos dois. Erros: `progression.level_not_earned`, `progression.level_skip`, `progression.level_decrease`. Benefícios por nível: orçamentos e derivados que leem o nível (ex. talentos liberados no nível 3).

### 6.8 Recursos e inventário

- `resources`: `{"id", "path", "max": fórmula, "min", "recovery"}`; fora do intervalo: `resource.out_of_range`.
- `inventory`: `{"id", "collection", "weight_field", "quantity_field", "equipped_field", "capacity": fórmula}`; acima da capacidade: `inventory.over_capacity`.

### 6.9 Rolagens (`rolls`)

Estratégias genéricas, nunca nomes de jogos:

- `sum`: `dice` (expressão `1d20`, `2d6+1`, `4d6kh3`), `modifier` (fórmula), `target` `{default, min, max}`, `critical_at_least`, `fumble_at_most`, `edge: {"extra_dice": 1}` (vantagem/desvantagem: um dado a mais, fica o melhor/pior).
- `pool`: `count` (fórmula), `faces`, `difficulty` `{default, min, max}`, `required`, `cancel_at_most` (faces que anulam sucessos), `bonus_at_least` + `bonus_successes`, `explode_at_least` (limitado a 100 dados), `botch_at_most` (falha crítica sem sucessos).
- `inputs`: `{"attribute": {"targets": ["/attributes/physical/*"]}}`. Quem rola só escolhe um caminho entre os alvos e, se declarados, dificuldade/alvo/vantagem dentro dos limites. Faces, quantidade e modificadores vêm sempre do preset e da ficha, no servidor.

Ex.: reserva atributo + habilidade em d10, dificuldade 2–10, 1 anula sucesso:

```json
{"id": "test", "label": "Teste", "strategy": "pool", "faces": 10,
 "inputs": {"attribute": {"targets": ["/attributes/physical/*"]}, "skill": {"targets": ["/skills/*"]}},
 "count": {"op": "add", "args": [{"var": "attribute"}, {"var": "skill"}]},
 "difficulty": {"default": 6, "min": 2, "max": 10}, "cancel_at_most": 1}
```

### 6.10 Governança

`governance.lock_during_session` (padrão verdadeiro em v2): com sessão ao vivo ativa na campanha, jogadores não enviam propostas (`409 character.locked_by_session`); rascunhos continuam.

## 7. Compêndio (`novtt.compendium/1`)

Separado das regras. `taxonomy` declara as categorias do jogo com rótulos próprios (`lineage` = "Linhagem", `vocation` = "Vocação", `parent_kind` para subcategorias). Cada item: `canonical_id`, `kind` (da taxonomia), `labels`, `rules_revision`, `locale`, `summary` curto e original, `source` (`source_id`, `source_authenticity`, `rights_status`, `license_id`, `review_status`, `attribution_ref`, `location`), `mechanical_refs` (`choice:<escolha>:<valor>`, `effect:<id>`, `derived:<id>`, `roll:<id>`, `cost:<id>`, `resource:<id>`, `track:<id>`, `constraint:<id>`) e `content_hash` (sha256 do JSON canônico do item sem o próprio hash). `attributions` guarda os textos referenciados.

Nunca troque a categoria do jogo por outra mais genérica ("clã" não vira "classe"). Valores sem fonte verificada ficam `review_status: unverified` e não são publicados.

## 8. Direitos e publicação

Capacidade técnica não é licença. Consulte `novtt-handoff/docs/legal/LICENSE-MATRIX.md`.

- Importação pessoal: privada ao dono, nunca pública, aceita qualquer `rights_status`.
- Publicação canônica (só a equipe, `php artisan novtt:preset:publish <pasta> --actor=<e-mail>`): exige manifesto v2 e passa pelo portão de direitos: `rights.not_approved`, `rights.quarantined`, `rights.license_missing`, `rights.attribution_missing`, `rights.provenance_missing`, `rights.unverified_source`, `rights.content_hash_mismatch`, `rights.manifest_without_rights`.
- O catálogo público lista só versões `published` com direitos `approved`/`published`. Colocar em quarentena (`php artisan novtt:preset:rights <id> quarantined --reason=...`) tira do catálogo sem quebrar campanhas que já usam a versão.
- SRD de D&D: só o conteúdo efetivamente coberto, com a atribuição exata do documento. World of Darkness: **bloqueado** até autorização escrita.

## 9. Erros

Formato `novtt.validation-result/1`: `rule_id`, `severity`, `operation_ids`, `paths`, `params`. Etapas: `json.syntax` → contratos (`schema.*`, caminho prefixado pela parte) → compatibilidade (`ui.*`, `rules.*`, `formula.*`, `compendium.*`, `engine.*`, `UNSUPPORTED_CAPABILITY`). Erros de ficha: `op.*`, `budget.*`, `limit.*`, `dependency.*`, `choice.*`, `constraint.failed`, `progression.*`, `resource.out_of_range`, `inventory.over_capacity`, `derived.evaluation_failed`. Jogadores nunca recebem caminhos que não podem ler (`validation.restricted`).

## 10. Passo a passo local

1. Copie `presets/novtt-demo/demo-linhagens/1.0.0` para uma pasta nova e troque `variant_id`, `display_name`, licença e proveniência.
2. Edite schema, interface e regras. Mantenha ids estáveis.
3. Rode a engine: `sh scripts/dev.sh engine` (inclui os casos de `invalid-examples.json`).
4. Importe na sua biblioteca: `POST /api/v1/presets/imports` com `{"manifest": ..., "sheet_schema": ..., "sheet_ui": ..., "rules": ..., "compendium": ...}`. Leia o `validation-result` e corrija pelo `rule_id` e `paths`.
5. Crie uma campanha com a versão importada e um personagem; percorra as etapas de criação; peça aprovação ao mestre.

## 11. Instruções para IA

- Leia `AGENTS.md`, este manual, os contratos em `contracts/presets/` e um dos presets de `presets/novtt-demo` antes de gerar JSON.
- Não invente operadores, campos ou capacidades. Se faltar suporte, registre `UNSUPPORTED_CAPABILITY` e proponha extensão separada.
- Use só os nós da seção 6.1. Toda referência (`ref`, `derived`, `budget`, `item`, alvos) precisa existir.
- Preserve a taxonomia e a nomenclatura do jogo **somente** quando a licença permitir; sem direitos, use nomes próprios.
- Não copie texto longo, tabelas narrativas, ilustrações ou layout de terceiros; rótulos curtos e funcionais e resumos originais.
- JSON determinístico, sem código, sem URLs de dados, sem ciclos.
- Para cada orçamento, custo, escolha, restrição, trilha, rolagem e permissão, gere um caso válido e um inválido (siga o formato de `invalid-examples.json`).
- Registre versão do preset, `rules_revision`, `source_version`, versão da engine e o resultado da validação.

## 12. Fora desta versão

Testes opostos e resistências como estratégias próprias, expiração automática de efeitos, gasto de recursos ao vivo a partir das regras, rolagens do preset nas rolagens oficiais da mesa ao vivo, suíte de conformidade dentro do pacote, herança entre edições (`extends`), editor visual, marketplace e importação de SRD.

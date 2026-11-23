# Manual de autoria de presets (contratos v1)

Para humanos e agentes de IA. Fonte normativa: [SPEC-PRESET-AUTHORING-001](../specs/SPEC-PRESET-AUTHORING-001.md), [SPEC-PRESETS-001](../specs/SPEC-PRESETS-001.md), [ADR 0010](../adr/0010-sheet-change-validation-layers.md). Exemplo completo e testado: [`presets/fixtures/demo-basic/1.0.0`](../../presets/fixtures/demo-basic/1.0.0).

Este manual descreve **apenas** o que a engine `0.1.0` executa. Qualquer recurso fora daqui é rejeitado com `UNSUPPORTED_CAPABILITY`. Validação de JSON Schema não equivale a compatibilidade com a engine.

## 1. Pacote

Um pacote é uma versão imutável de uma variante. Quatro partes, enviadas juntas em `POST /api/v1/presets/imports` (máx. 512 KiB):

| Parte | Contrato | Arquivo |
|---|---|---|
| `manifest` | `novtt.preset-manifest/1` (`contracts/presets/preset-manifest.v1.schema.json`) | `manifest.json` |
| `sheet_schema` | JSON Schema Draft 2020-12, subconjunto da seção 3 | `sheet.schema.json` |
| `sheet_ui` | `novtt.sheet-ui/1` (`contracts/presets/sheet-ui.v1.schema.json`) | `sheet.ui.json` |
| `rules` | `novtt.rules/1` (`contracts/presets/rules.v1.schema.json`) | `rules.json` |

Correções nunca alteram uma versão publicada: crie `version` nova (semver). Reenviar o mesmo conteúdo é idempotente; conteúdo diferente com a mesma versão é recusado (`409 preset.version_exists`).

## 2. Manifesto

`family_id` e `variant_id`: slugs próprios (`[a-z0-9-]`), sem marcas de terceiros na interface. `license` e `provenance` são obrigatórios: declare de onde vem a estrutura e sob qual licença. `engine.min_version` acima da engine instalada é rejeitado.

## 3. Ficha (`sheet_schema`)

- Raiz `type: object`. Declare cada campo explicitamente em `properties`.
- Palavras-chave aceitas: `type`, `properties`, `additionalProperties`, `required`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `enum`, `const`, `default`, `items`, `minItems`, `maxItems`, `maxProperties`, `propertyNames`, `format`, `title`, `description`, `$schema`.
- **Proibido**: `$ref`, `$id`, `allOf`/`anyOf`/`oneOf`/`not`, `if`/`then`/`else` e qualquer outra palavra-chave (`UNSUPPORTED_CAPABILITY`).
- Propriedades iniciadas por `_` são reservadas à engine (`schema.reserved_property`).
- **Coleções**: `type: array` de objetos com `id` string obrigatório (identificador estável). Use `maxItems`.
- Bolinhas são `integer` com `minimum`/`maximum`; o valor ausente vale `default`, senão `minimum`, senão 0.
- Até 5000 nós de schema.

## 4. Interface e acesso (`sheet_ui`)

Seções com elementos `field` (escalar existente no schema) ou `collection` (array de objetos com `id`). Cada caminho é um JSON Pointer existente, sem sobreposição entre elementos.

Acesso efetivo = do elemento, senão da seção, senão `default_access`. Caminho não declarado = `master_only`.

| Acesso | Jogador lê | Jogador propõe |
|---|---|---|
| `editable` | sim | sim |
| `approval_required` | sim | sim (sempre revisado) |
| `read_only` | sim | não |
| `hidden` | não | não |
| `master_only` | não | não |

Em fichas de campanha toda mudança permanente passa pela revisão do mestre; o nível de acesso decide **quem pode propor** e **quem pode ler**. Dados não declarados nunca são enviados a ninguém.

Widgets: `text`, `textarea`, `integer`, `number`, `boolean`, `dot_rating` (exige `integer`), `select`.

## 5. Regras (`rules`)

- **Orçamentos** (`budgets`): `initial`, `bonus`, `experience`, `other`, com `initial_amount`. Concessões do mestre somam saldo e nunca gastam.
- **Custos** (`costs`): por fase (`creation` até o personagem ser aprovado; `advancement` depois), orçamento, alvos (`/caminho` ou `/pai/*`) e modelo:
  - `fixed_per_dot` (`amount` por ponto);
  - `current_rating_multiplier` (valor atual × `multiplier`; `when_zero` para o primeiro ponto);
  - `new_rating_multiplier` (novo valor × `multiplier`).
  Para cada ponto, a primeira regra da fase cujo orçamento cobre o custo paga (ex.: pontos iniciais, depois bônus). `free_dots` marca pontos impressos gratuitos.
- **Limites por fase** (`phase_limits`): máximo para alvos numa fase.
- **Dependências** (`dependencies`): `path` preenchido exige `requires` preenchidos; ciclos são rejeitados.

Proveniência: cada ponto comprado fica registrado com o orçamento que pagou; baixar um valor devolve o custo ao **orçamento de origem**. A cobrança segue a ordem das operações da proposta.

## 6. Ciclo de vida e importação

`draft → structurally_valid → engine_compatible → reviewed → published` (só avança). Importação pessoal termina em `engine_compatible`, privada ao dono e sem selo canônico; pode ser fixada nas campanhas do próprio dono. Publicação canônica só pela equipe, após revisão, via `php artisan novtt:preset:publish <pasta> --actor=<e-mail>`.

Erros vêm em `novtt.validation-result/1`: `rule_id`, `severity`, `operation_ids`, `paths`, `params`. Etapas: `json.syntax` → erros de contrato (`schema.*` com caminho prefixado pela parte, ex. `/sheet_ui/sections/0`) → compatibilidade (`ui.*`, `rules.*`, `engine.*`, `UNSUPPORTED_CAPABILITY`).

## 7. Instruções para IA

- Leia `AGENTS.md`, este manual, os quatro contratos e o fixture `demo-basic` antes de gerar qualquer JSON.
- Não invente operadores, campos ou capacidades. Se faltar suporte, registre `UNSUPPORTED_CAPABILITY` e proponha extensão de contrato separada.
- Não copie texto longo, tabelas narrativas, ilustrações ou layout de livros de terceiros; labels curtos e funcionais apenas.
- JSON determinístico, sem código, sem referências externas, sem ciclos.
- Gere exemplos positivos e negativos para cada orçamento, custo, fase, limite, dependência e permissão.
- Registre versão do preset, da engine e dos contratos, e o resultado da validação.

## 8. Fora desta versão

Valores derivados, efeitos e condições, rolagens a partir da ficha, herança entre edições (`extends`), editor visual e marketplace.

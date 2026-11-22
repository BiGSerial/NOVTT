# Sprint 0 — Inventário e baseline

Data: 2026-11-21
Branch: `docs/sprint-0-inventory` (a partir de `develop` em `9197df1`)
Referência: [SPEC-SPRINT-000](../specs/SPEC-SPRINT-000.md)

Este relatório registra o estado real do repositório antes das alterações da Sprint 0. Nada aqui é decisão aprovada; os itens em "Decisões para o dono" aguardam validação.

## 1. Branches e histórico

- Remoto: apenas `main` (`0feede7`, visão inicial) e `develop` (branch padrão).
- `develop` consolida as antigas `chore/architecture-foundation`, `chore/docker-dev-environment` e `docs/specs-i18n-presets`, removidas do remoto após verificação de que estavam integralmente contidas.
- Proteções ativas (rulesets): `main` e `develop` sem exclusão nem force-push; `main` só por PR com checks `engine`, `player-pwa` e `branch-policy`.
- `main` está 167 commits atrás de `develop`; ainda não houve release.

## 2. Módulos existentes

| Caminho | Estado | Observações |
|---|---|---|
| `packages/engine` | Biblioteca Composer, PHP `^8.5`, PHPUnit 12, `composer.lock` versionado | Dados, política e orquestração de rolagens oficiais; sem dependência de framework (verificado: `require` só contém `php`). ~350 linhas em `src/`. |
| `apps/player-pwa` | Vue 3.5, Vite 7, TypeScript 5.9, Vitest 5, `package-lock.json` versionado | Rolagem livre offline, service worker com build id. |
| `apps/master-web` | Apenas `resources/js/*.ts` | Sem manifesto próprio; seus testes estão em `apps/player-pwa/tests` e importam `../../master-web/...`. |
| `apps/api` | **Inexistente** | Reservado para Laravel. |
| `contracts/` | 5 JSON Schemas (draft 2020-12) | Sem `$id`, sem OpenAPI/AsyncAPI, sem validação em CI. |
| `presets/sangue-suga/v20` | Scaffold (`status: scaffold`) | Manifesto + schema de ficha genérico, sem conteúdo narrativo. |

## 3. Ferramentas e versões

| Ferramenta | Host (WSL2) | Docker / CI | Fonte |
|---|---|---|---|
| PHP | 8.4.21 (incompatível com a engine) | 8.5.13 (`php:8.5-cli-bookworm`) / `setup-php` 8.5 | `composer.json`, ADR 0002 |
| Composer | 2.8.9 | `composer:2` | |
| Node | 24.22.0 | 22 (`node:22-bookworm-slim`) / 22 | `compose.yaml`, workflow |
| npm | 11.19.1 | 10.9.9 | |
| PostgreSQL | — | `postgres:17-alpine` (opt-in) | `compose.yaml` |
| Redis | — | `redis:7-alpine` (opt-in, sem porta publicada) | `compose.yaml` |
| Docker / Compose | 28.3.1 / v2.38.1 | | |

Versões disponíveis no Packagist (consultadas em 2026-11-21): `laravel/laravel` v13.11.0, `laravel/framework` v13.43.0, `laravel/sanctum` v4.5.0, `laravel/reverb` v1.14.0, `phpunit/phpunit` 13.4.8 (engine usa 12.5.45).

## 4. Baseline executada

Comando: `sh scripts/dev.sh test` (Docker), em 2026-11-21, duração ~43 s com imagens em cache.

| Etapa | Resultado |
|---|---|
| Engine — PHPUnit 12.5.45, PHP 8.5.13 | OK, 18 testes, 32 asserções |
| Engine — `composer audit` | Nenhum advisory |
| PWA — `npm ci` | 73 pacotes, 0 vulnerabilidades |
| PWA — Vitest 5.1.1 | 1 arquivo, 4 testes passando |
| PWA — `vue-tsc --noEmit && vite build` | OK, JS 70,67 kB (28,15 kB gzip) |
| CI GitHub em `develop` (`9197df1`) | `success` |

Não executado: lint/análise estática (não há ferramenta configurada), validação dos JSON Schemas, testes de `apps/master-web` fora do PWA, serviços `infra` (PostgreSQL/Redis), testes em navegador real.

Observação: `scripts/dev.sh` criou `.env` a partir de `.env.example` com a senha placeholder; o arquivo é ignorado pelo Git.

## 5. Riscos e lacunas

| # | Risco | Severidade | Entrega |
|---|---|---|---|
| R1 | `apps/api` não existe; nenhum fluxo autoritativo ponta a ponta. | Alta | S0-02 |
| R2 | Sem lint/análise estática (PHPStan, PHP-CS-Fixer, ESLint). | Média | S0-05 |
| R3 | CI usa `npm install` em vez de `npm ci`: instalação não determinística. | Média | S0-01/S0-05 |
| R4 | Contratos sem `$id`, sem OpenAPI/AsyncAPI e sem validação automática. | Média | S0-03/S0-05 |
| R5 | `master-web` sem manifesto; testes acoplados ao PWA por caminho relativo. | Média | S0-02 |
| R6 | `scripts/dev.sh` aceita a senha placeholder do `.env.example`. | Baixa (dev local) | S0-01/S0-04 |
| R7 | Imagens Docker e actions do GitHub fixadas por tag, não por digest/SHA. | Baixa | S0-01/S0-05 |
| R8 | Divergência Node: host 24, Docker/CI 22. | Baixa | S0-01 |
| R9 | PHP do host (8.4) não roda a engine; desenvolvimento depende do Docker. | Informativo | S0-01 |
| R10 | Nome de família `sangue-suga` / edição `v20` remete a produto comercial; confirmar que não há conflito de marca. | A confirmar | Presets |

## 6. Sequência de commits proposta

Cada item em branch curta a partir de `develop`, PR para `develop`, sem coautoria.

1. `docs/sprint-0-inventory` — este relatório.
2. `chore/reproducible-dev-env` — `npm ci` na CI, versões alinhadas (R3, R8), recusa de senha placeholder (R6), versões suportadas documentadas.
3. `feature/bootstrap-laravel-api` — `apps/api` Laravel 13 com engine via path repository, healthcheck, testes de smoke; sem autenticação ainda (R1).
4. `docs/initial-contracts` — identificadores/invariantes de domínio, rascunho OpenAPI e AsyncAPI, envelopes de erro, matriz de permissões (R4); decisões abertas como ADRs propostos.
5. `ci/baseline-checks` — job da API, validação de schemas, PHPStan/ESLint, smoke test do Compose (R2, R4, R7).
6. `refactor/master-web-package` — manifesto próprio e testes no próprio módulo (R5), se aprovado.

## 7. Decisões do dono (2026-11-21)

| # | Tema | Decisão |
|---|---|---|
| 1 | Node | Padronizar em **Node 24 LTS** (host, Docker e CI). |
| 2 | PostgreSQL | **PostgreSQL 18** para a `apps/api`. |
| 3 | Laravel | Bootstrap de `apps/api` com **Laravel 13 sem autenticação**; Sanctum fica para ADR próprio (S0-04). |
| 4 | Qualidade | Adotar **PHPStan**, **ESLint** e **validação de schemas** na CI. PHP-CS-Fixer não aprovado. |

### Ainda abertas

- **master-web**: separar em pacote próprio (R5) nesta sprint ou adiar?
- **PHPUnit 13**: atualizar a engine nesta sprint ou manter 12?
- **Preset `sangue-suga/v20`**: manter o nome ou renomear para algo neutro (R10)?
- **PHPStan**: nível de rigor (proposta: nível máximo na engine, nível 6+ na API).

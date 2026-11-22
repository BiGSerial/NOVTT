# Fluxo de branches

O NOVTT segue um fluxo derivado do Git Flow. Releases saem da `develop`.

## Branches permanentes

| Branch    | Papel                                                    | Proteção                                                        |
|-----------|----------------------------------------------------------|-----------------------------------------------------------------|
| `main`    | Código liberado. Cada merge corresponde a um release.     | Somente via PR com CI verde; sem force-push nem exclusão.        |
| `develop` | Integração contínua; branch padrão do repositório.        | Sem force-push nem exclusão.                                     |

## Branches de trabalho

Formato: `<tipo>/<descricao-curta>` em minúsculas, com letras, números, `.`, `_` ou `-`.
Exemplos: `feature/fichas-dinamicas`, `fix/sw-cache-build-id`, `release/0.1.0`.

| Prefixo     | Uso                                                  | Sai de    | PR para               |
|-------------|------------------------------------------------------|-----------|-----------------------|
| `feature/`  | Nova funcionalidade                                  | `develop` | `develop`             |
| `fix/`      | Correção de bug não urgente                          | `develop` | `develop`             |
| `chore/`    | Manutenção, tooling, dependências                    | `develop` | `develop`             |
| `docs/`     | Documentação, ADRs e specs                           | `develop` | `develop`             |
| `refactor/` | Refatoração sem mudança de comportamento             | `develop` | `develop`             |
| `test/`     | Testes                                               | `develop` | `develop`             |
| `ci/`       | Pipelines e automação                                | `develop` | `develop`             |
| `perf/`     | Melhoria de desempenho medida                        | `develop` | `develop`             |
| `release/`  | Preparação de versão (`release/X.Y.Z`)               | `develop` | `main`, depois `develop` |
| `hotfix/`   | Correção urgente em produção                         | `main`    | `main`, depois `develop` |

Os prefixos coincidem com os tipos de Conventional Commits usados nas mensagens de commit.

## Release

1. `git switch -c release/X.Y.Z develop`; ajustar versão e changelog.
2. Abrir PR `release/X.Y.Z` → `main`; fazer merge com a CI verde.
3. Criar a tag `vX.Y.Z` no commit de merge da `main`.
4. Levar a `main` de volta para a `develop` (PR `release/X.Y.Z` → `develop` ou merge da `main`).

Para hotfix, o mesmo fluxo partindo da `main`.

## Verificação automática

O job `branch-policy` da CI (`.github/workflows/engine-tests.yml`) reprova PRs cujo nome de branch fuja do padrão
e PRs para `main` que não venham de `develop`, `release/*` ou `hotfix/*`. Ele é check obrigatório na `main`.

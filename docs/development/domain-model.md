# Modelo de domínio — Sprint 001

Fonte: [SPEC-SPRINT-001](../specs/SPEC-SPRINT-001-approved.md), [ADR 0009](../adr/0009-api-authentication.md), [ADR 0010](../adr/0010-sheet-change-validation-layers.md). Migrations em `apps/api/database/migrations/2026_11_22_*`.

Todas as chaves expostas são UUIDv7. Invariantes marcadas com **DB** são garantidas pelo PostgreSQL, mesmo que o código da aplicação falhe.

## Presets

| Tabela | Papel | Invariantes |
|---|---|---|
| `preset_families` | Agrupa variantes | `owner_user_id` nulo = catálogo canônico; slug único no canônico e por dono (**DB**) |
| `preset_definitions` | Variante | `(family_id, variant_slug)` único (**DB**) |
| `preset_versions` | Versão imutável | conteúdo imutável após inserção, sem exclusão, status só avança `draft → structurally_valid → engine_compatible → reviewed → published` (**DB**, trigger); semver e hash SHA-256 (**DB**) |
| `user_preset_library` | Biblioteca pessoal | referencia versões; nunca atribui selo canônico |

## Campanhas

| Tabela | Invariantes |
|---|---|
| `campaigns` | fixa `preset_version_id` (FK `RESTRICT`); `revision` para concorrência otimista; arquivamento ≠ exclusão |
| `campaign_members` | `(campaign_id, user_id)` único; remoção é status, não exclusão |
| `campaign_invitations` | apenas hash SHA-256 do token; `uses ≤ max_uses` (**DB**); expiração obrigatória |

## Personagens e NPCs

| Tabela | Invariantes |
|---|---|
| `character_library` | entidade pessoal, distinta da instância de campanha |
| `campaign_characters` | cópia fixada na versão do preset; `sheet` é o snapshot canônico; `sheet_revision` monotônica; custódia temporária em `custodian_user_id` |
| `character_sheet_revisions` | histórico imutável, `(character, revision)` único (**DB**, append-only) |
| `character_grants` | concessões de orçamento (inicial, bônus, XP); conceder não gasta (**DB**, append-only) |
| `npc_library` / `campaign_npcs` | NPC de campanha é cópia sanitizada; `private_notes` nunca é copiado |

## Propostas e revisão

| Tabela | Invariantes |
|---|---|
| `character_drafts` | rascunho separado da ficha oficial; um por autor e personagem; `draft_version` |
| `change_requests` | no máximo **uma ativa** (`submitted`, `in_review`, `awaiting_resolution`) por personagem (**DB**, índice parcial único); `(author, idempotency_key)` único (**DB**); `applied`/`rejected` exigem finalização e `applied` exige revisão resultante (**DB**) |
| `change_operations` | operações tipadas, ordenadas, imutáveis (**DB**) |
| `operation_decisions` | uma por operação; rejeição exige motivo de 10 a 1000 caracteres (**DB**); provisória editável, final congelada (**DB**) |

## Auditoria e outbox

`audit_events` e `outbox_events` são gravados na mesma transação da mudança. `audit_events` é append-only (**DB**) e não guarda segredos.

## Autorização (policies)

| Ação | Quem |
|---|---|
| Ver campanha | membro ativo |
| Gerenciar campanha (convites, membros, NPCs, revisão) | mestre ativo, campanha ativa |
| Ver personagem | mestres; jogador somente os próprios |
| Rascunho e proposta | quem controla o personagem (custodiante, senão jogador) |
| Aprovar, conceder pontos, transferir, custódia, arquivar | mestre |
| Ver preset | canônico publicado: qualquer usuário; pessoal: dono e membros de campanhas que o fixam |
| Fixar preset em campanha | canônico publicado, ou versão pessoal `engine_compatible`+ do próprio dono |

Recurso não visível responde **404**. A visibilidade por campo (`hidden`, `read_only`, `editable`, `approval_required`, `master_only`) é aplicada pela projeção da ficha (S001-04/05).

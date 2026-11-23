# Fluxo de revisão de fichas (Sprint 001)

Contrato HTTP: [`contracts/http/openapi.v1.yaml`](../../contracts/http/openapi.v1.yaml). Regras: [SPEC-SPRINT-001](../specs/SPEC-SPRINT-001-approved.md) 4–11.

```
jogador: PUT draft (draft_version) ─► POST change-requests (Idempotency-Key)
mestre:  PUT decisions (review_version) … ─► POST finalize (Idempotency-Key)
                                              └─► nova revisão da ficha, ou nada
mestre:  POST approve (após a primeira revisão aplicada) ─► fase advancement
```

| Etapa | Garantias |
|---|---|
| Rascunho | Separado da ficha oficial; `draft_version` evita sobrescrita; prévia filtrada (campos ocultos nunca aparecem em erros; `validation.restricted` substitui o que foi removido). |
| Submissão | Base deve ser a revisão atual (`409 draft.stale`), conjunto inteiro válido, no máximo uma proposta ativa (`409 change_request.active_exists`), idempotente. |
| Decisão | Por operação; lote só dentro de uma seção e só provisório; rejeição com motivo próprio (10–1000); lote não sobrescreve rejeição (`override` individual); `review_version` (`409`). |
| Finalização | Todas decididas (`409 decision.incomplete`); lock da linha do personagem; conjunto aprovado validado inteiro com a autoridade atual do autor (`422 change_request.approved_set_invalid`, nada muda); ficha, revisão, snapshot, decisões finais, auditoria e outbox numa transação. Rejeição total não cria revisão. |
| Rebase | `POST draft/rebase-preview` (três vias, sem mutação, conflitos `value_changed`, `target_removed`, `item_removed`, `previously_rejected`) e `POST draft/rebase` com resoluções explícitas e token. |
| Pontos | Mestre concede (`grants`); concessão não gasta; gasto exige proposta revisada. |

Erros: `application/problem+json` com `code` estável. Recurso invisível: 404.

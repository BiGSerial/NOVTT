# Modo demonstração (somente local)

Permite usar no navegador o fluxo da Sprint 001 (ficha → proposta → revisão do mestre) enquanto
cadastro e login reais não existem (ADR 0009, fora do escopo da sprint).

```sh
sh scripts/dev.sh demo        # API + PostgreSQL + PWA, migrations e seed
# abra http://localhost:5173/demo.html
sh scripts/dev.sh demo-reset  # apaga o banco local de desenvolvimento e recria a demo
sh scripts/dev.sh down
```

## O que existe

- `novtt:demo:seed` (idempotente): mestre e jogador fictícios (`*@demo.novtt.invalid`, senha
  aleatória nunca exibida), preset neutro `demo-basic` importado pelo importador real em três
  etapas, campanha fixada nessa versão e a personagem "Aria" em criação.
- `GET|POST|DELETE /api/dev/demo[/session]`: escolhe com qual usuário semeado o navegador entra,
  pela sessão first-party do Sanctum. Fora do contrato versionado (`/api/v1`).
- `demo.html` no PWA: entrada só de desenvolvimento (o build de produção gera apenas `index.html`).
  O jogador abre o editor real (`/#/personagens/<id>`); o mestre usa o painel de revisão real
  (`apps/master-web/resources/js/review-panel.ts`) e pode aprovar o personagem.

Depois do login, tudo passa pelas rotas, policies, idempotência e auditoria reais.

## Travas de segurança

- Ativo só com `NOVTT_DEMO_MODE=true` **e** `APP_ENV` `local` ou `testing`; em qualquer outro
  ambiente as rotas respondem 404 e o comando falha (`DemoModeTest`).
- Sessão apenas para origens first-party (`SANCTUM_STATEFUL_DOMAINS`), com CSRF.
- O servidor de desenvolvimento do Vite faz proxy de `/api` e `/sanctum`, então PWA e API
  compartilham a origem e o cookie de sessão não precisa de CORS com credenciais.

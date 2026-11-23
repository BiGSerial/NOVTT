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

## Mesa ao vivo (SPEC-MVP-002)

O seed também cria **A Ponte de Vau Cinzento (demo)** no preset original `novtt-mesa`: mestre,
três jogadores (`player`, `player2`, `player3` no login de demo) com Lyra, Bram e Nix aprovados,
três PdMs com vida, um roteiro (que semeia bússola, relógios e pistas ao iniciar a sessão), Atlas e
uma nota fixada. Abra uma janela por papel (janela anônima ou outro navegador para cada um):

- `http://localhost:5173/` → **Mestre** (mesa do mestre no desktop) ou **Lyra / Bram / Nix** (celular).
- `http://localhost:5173/#/tablet` num tablet (ou outra janela): mostra um código; o mestre toca em
  **Parear tablet** e digita o código. O fichário segue a seleção do mestre.
- `sh scripts/dev.sh e2e`: abre mestre, jogadora e tablet num Chromium headless e confere que as
  telas mostram o mesmo resultado oficial, que a rolagem secreta não vaza, que o sussurro chega só
  à destinatária e que o pareamento funciona. Capturas em `e2e-output/`.

## Travas de segurança

- Ativo só com `NOVTT_DEMO_MODE=true` **e** `APP_ENV` `local` ou `testing`; em qualquer outro
  ambiente as rotas respondem 404 e o comando falha (`DemoModeTest`).
- Sessão apenas para origens first-party (`SANCTUM_STATEFUL_DOMAINS`), com CSRF.
- O servidor de desenvolvimento do Vite faz proxy de `/api` e `/sanctum`, então PWA e API
  compartilham a origem e o cookie de sessão não precisa de CORS com credenciais.

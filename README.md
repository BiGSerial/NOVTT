# NOVTT

**NOT OTHER VIRTUAL TABLE TOP**

NOVTT é uma plataforma de suporte a RPG de mesa, sem tabuleiro virtual obrigatório: aplicativo Android nativo e PWA para jogadores, painel web multijanela para mestres e backend autoritativo em tempo real.

## Diretrizes

- Responsabilidade única, contratos explícitos e módulos com fronteiras definidas.
- Engine genérica de regras, independente dos presets e da interface.
- Presets declarativos versionados por família e edição, personalizáveis por campanha.
- Mestre controla permissões e mudanças de fichas.
- Conteúdo narrativo pertence aos usuários; não distribuir material protegido de terceiros.
- API HTTP versionada e eventos de tempo real com payloads documentados.

## Stack planejada

- Backend: PHP / Laravel, PostgreSQL, Redis e Laravel Reverb.
- Android: Kotlin e Jetpack Compose.
- PWA do jogador: Vue e TypeScript, com paridade funcional em relação ao Android.
- Painel web: Laravel e Livewire.
- Contratos: OpenAPI, AsyncAPI e JSON Schema.

## Estado

Fundação em desenvolvimento: biblioteca independente em `packages/engine`, com testes PHPUnit; PWA inicial de dados livres em `apps/player-pwa`; sincronização inicial de janelas do mestre em `apps/master-web`. A aplicação Laravel em `apps/api` ainda não foi criada.

## Desenvolvimento local com Docker

O ambiente Docker Compose permite testar o motor PHP, executar o PWA com hot reload e iniciar PostgreSQL/Redis sob demanda.

```sh
cp .env.example .env
# Edite NOVTT_DB_PASSWORD no .env
sh scripts/dev.sh setup
sh scripts/dev.sh test
sh scripts/dev.sh player
```

Acesse o PWA em http://localhost:5173. Para iniciar apenas infraestrutura: `sh scripts/dev.sh infra`.

**Atenção:** os lockfiles do Composer e npm ainda precisam ser gerados e versionados para que o bootstrap seja totalmente reproduzível. Consulte [o roteiro Docker](docs/development/docker.md), incluindo comandos, pré-requisitos, diagnóstico, limitações e critérios de aceite.

## Motor de regras

O motor é um pacote Composer isolado da infraestrutura Laravel. Para executar os testes:

```bash
cd packages/engine
composer install
composer test
```

A futura aplicação Laravel em `apps/api` poderá declarar o pacote como dependência Composer local do tipo `path`, apontando para `../../packages/engine`. Essa integração será implementada quando a aplicação existir.

## Autoria

Projeto NOVTT de BiGSerial. Ferramentas de IA podem auxiliar no desenvolvimento, sem atribuição de coautoria.

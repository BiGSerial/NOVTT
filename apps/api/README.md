# NOVTT API

Backend Laravel 13 autoritativo do NOVTT. Consome `packages/engine` via repositório Composer local; regras de domínio ficam na engine, não aqui.

- Requisitos: PHP 8.5, Composer 2 (ou Docker, ver `docs/development/docker.md`).
- Testes: `sh scripts/dev.sh api-test` na raiz, ou `composer test` neste diretório após `cp .env.example .env && php artisan key:generate`.
- Servidor local: `sh scripts/dev.sh api` → `http://localhost:8000/up`.

Estado: esqueleto sem autenticação nem endpoints de domínio. Rotas HTTP versionadas serão adicionadas conforme os contratos em `contracts/`.

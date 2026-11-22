# Desenvolvimento local com Docker

## Pré-requisitos
Docker Engine com Compose v2 (ou Docker Desktop), Git e portas locais disponíveis. Não é necessário instalar PHP, Composer ou Node no host.

## Primeira execução
```sh
git clone https://github.com/BiGSerial/NOVTT.git
cd NOVTT
cp .env.example .env
# Edite .env e substitua NOVTT_DB_PASSWORD por uma senha exclusiva.
sh scripts/dev.sh setup
sh scripts/dev.sh test
```

## Ciclo por etapa
```sh
sh scripts/dev.sh engine # dependências e testes do motor PHP
sh scripts/dev.sh api-test # testes da API Laravel
sh scripts/dev.sh api    # API em http://localhost:8000 (healthcheck em /up)
sh scripts/dev.sh player # PWA com hot reload: http://localhost:5173
sh scripts/dev.sh test   # engine + API + Vitest + build TypeScript/Vite
sh scripts/dev.sh infra  # PostgreSQL e Redis, quando necessários
sh scripts/dev.sh logs
sh scripts/dev.sh down
```

Os comandos devem ser executados na raiz do repositório. No Windows, use WSL2 com integração Docker Desktop.

## Limites atuais
- `apps/master-web` ainda não tem manifesto de execução independente; não há serviço Docker para ele.
- `apps/api` cria `apps/api/.env` a partir de `.env.example` e gera `APP_KEY` na primeira execução. Testes usam SQLite em memória; o `.env` aponta para os serviços `postgres` e `redis` (copie a senha de `NOVTT_DB_PASSWORD` para `DB_PASSWORD`). PostgreSQL e Redis continuam opt-in.
- A extensão `phpredis` ainda não está na imagem PHP; instalar quando a API passar a usar Redis.
- O motor requer PHP 8.5 conforme `packages/engine/composer.json`; não reduzir silenciosamente a versão.
- `npm ci` exige `apps/player-pwa/package-lock.json`. Se o lockfile não existir, gerar e versionar antes de usar o fluxo.
- Não publicar portas de banco/cache, não versionar `.env` e não usar dados reais de usuários.
- Volumes preservam dados entre execuções. `docker compose down -v` apaga dados e dependências persistidas; use apenas conscientemente.
- Imagens de runtime devem ser fixadas por digest após validação e atualizadas por processo controlado.

## Critérios de aceite por etapa
1. Construção das imagens sem erros.
2. Instalação determinística de dependências com lockfiles.
3. Testes unitários e build passando.
4. Hot reload funcional no PWA.
5. Nenhum serviço de dados exposto externamente.
6. Logs claros e comandos de diagnóstico reproduzíveis.

## Diagnóstico
```sh
docker compose version
docker compose --profile test config
docker compose --profile test run --rm engine php -v
docker compose --profile player run --rm --no-deps player node -v
```

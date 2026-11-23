#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
if [ ! -f .env ]; then
  cp .env.example .env
  echo "Arquivo .env criado. Defina uma senha local única em NOVTT_DB_PASSWORD antes de iniciar infra."
fi
require_db_password() {
  if grep -q '^NOVTT_DB_PASSWORD=replace-with-a-unique-local-password$' .env || ! grep -q '^NOVTT_DB_PASSWORD=..*' .env; then
    echo "Defina uma senha local única em NOVTT_DB_PASSWORD no .env antes de iniciar infra." >&2
    exit 1
  fi
}
case "${1:-help}" in
  setup) docker compose --profile test build engine; docker compose --profile test run --rm engine ;;
  test) docker compose --profile test run --build --rm engine; docker compose --profile test run --build --rm api-test; docker compose --profile player run --rm --no-deps player sh -c 'npm ci && npm test && npm run build' ;;
  api-test) docker compose --profile test run --build --rm api-test ;;
  api) require_db_password; docker compose --profile api up --build api ;;
  demo)
    # Local demonstration: API + PWA with a seeded campaign. Never use outside local.
    require_db_password
    export NOVTT_DEMO_MODE=true
    docker compose --profile api --profile player up -d --build api player
    echo "Aguardando a API…"
    i=0
    until docker compose --profile api exec -T api php artisan --version >/dev/null 2>&1 && curl -fs "http://127.0.0.1:${NOVTT_API_PORT:-8000}/up" >/dev/null 2>&1; do
      i=$((i + 1)); [ "$i" -gt 90 ] && { echo "A API não respondeu." >&2; exit 1; }; sleep 2
    done
    docker compose --profile api exec -T api php artisan migrate --force
    docker compose --profile api exec -T api php artisan novtt:demo:seed
    echo "Demonstração: http://localhost:${NOVTT_PLAYER_PORT:-5173}/demo.html"
    ;;
  demo-reset)
    # Drops the local development database and recreates the demonstration data.
    require_db_password
    NOVTT_DEMO_MODE=true docker compose --profile api exec -T api php artisan migrate:fresh --force
    NOVTT_DEMO_MODE=true docker compose --profile api exec -T api php artisan novtt:demo:seed
    ;;
  engine) docker compose --profile test run --build --rm engine ;;
  player) docker compose --profile player up player ;;
  infra) require_db_password; docker compose --profile infra up -d postgres redis ;;
  down) docker compose --profile infra --profile player --profile api down ;;
  logs) docker compose --profile infra --profile player --profile api logs -f ;;
  *) echo "Uso: sh scripts/dev.sh {setup|test|engine|api|api-test|player|demo|demo-reset|infra|down|logs}"; exit 2 ;;
esac

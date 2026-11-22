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
  api) docker compose --profile api up --build api ;;
  engine) docker compose --profile test run --build --rm engine ;;
  player) docker compose --profile player up player ;;
  infra) require_db_password; docker compose --profile infra up -d postgres redis ;;
  down) docker compose --profile infra --profile player --profile api down ;;
  logs) docker compose --profile infra --profile player --profile api logs -f ;;
  *) echo "Uso: sh scripts/dev.sh {setup|test|engine|api|api-test|player|infra|down|logs}"; exit 2 ;;
esac

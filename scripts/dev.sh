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
# Local Reverb credentials: generated once per checkout, kept only in the ignored .env.
ensure_reverb_keys() {
  if ! grep -q '^NOVTT_REVERB_APP_KEY=..*' .env; then
    printf '\nNOVTT_REVERB_APP_KEY=%s\nNOVTT_REVERB_APP_SECRET=%s\n' "$(od -An -tx1 -N16 /dev/urandom | tr -d ' \n')" "$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')" >> .env
    echo "Chaves locais do Reverb geradas no .env."
  fi
}
case "${1:-help}" in
  setup) docker compose --profile test build engine; docker compose --profile test run --rm engine ;;
  test) docker compose --profile test run --build --rm engine; docker compose --profile test run --build --rm api-test; docker compose --profile player run --rm --no-deps player sh -c 'npm ci && npm test && npm run build' ;;
  api-test) docker compose --profile test run --build --rm api-test ;;
  api) require_db_password; ensure_reverb_keys; docker compose --profile api up --build api reverb worker ;;
  demo)
    # Local demonstration: API + PWA with a seeded campaign. Never use outside local.
    require_db_password
    ensure_reverb_keys
    export NOVTT_DEMO_MODE=true
    docker compose --profile api --profile player up -d --build api reverb worker player
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
  e2e)
    # Browser end-to-end of the live table against the running demo (sh scripts/dev.sh demo).
    mkdir -p e2e-output
    docker run --rm --network container:novtt-player-1 -v "$PWD/scripts/e2e":/e2e:ro -v "$PWD/e2e-output":/out \
      mcr.microsoft.com/playwright:v1.65.1-noble sh -c "cd /tmp && npm i -s playwright@1.65.1 >/dev/null 2>&1 && cp /e2e/live-table.mjs . && node live-table.mjs; status=\$?; chown -R $(id -u):$(id -g) /out; exit \$status"
    ;;
  e2e-atmosphere)
    # Weather and time of day set by the master and seen by a player (needs: sh scripts/dev.sh demo).
    mkdir -p e2e-output
    docker run --rm --network container:novtt-player-1 -v "$PWD/scripts/e2e":/e2e:ro -v "$PWD/e2e-output":/out \
      mcr.microsoft.com/playwright:v1.65.1-noble sh -c "cd /tmp && npm i -s playwright@1.65.1 >/dev/null 2>&1 && cp /e2e/atmosphere.mjs . && node atmosphere.mjs; status=\$?; chown -R $(id -u):$(id -g) /out; exit \$status"
    ;;
  e2e-request)
    # A player's roll request on the master's cast token and sheet (needs: sh scripts/dev.sh demo).
    mkdir -p e2e-output
    docker run --rm --network container:novtt-player-1 -v "$PWD/scripts/e2e":/e2e:ro -v "$PWD/e2e-output":/out \
      mcr.microsoft.com/playwright:v1.65.1-noble sh -c "cd /tmp && npm i -s playwright@1.65.1 >/dev/null 2>&1 && cp /e2e/player-request.mjs . && node player-request.mjs; status=\$?; chown -R $(id -u):$(id -g) /out; exit \$status"
    ;;
  e2e-clocks)
    # Clock tone and visibility: chosen players only (needs: sh scripts/dev.sh demo).
    mkdir -p e2e-output
    docker run --rm --network container:novtt-player-1 -v "$PWD/scripts/e2e":/e2e:ro -v "$PWD/e2e-output":/out \
      mcr.microsoft.com/playwright:v1.65.1-noble sh -c "cd /tmp && npm i -s playwright@1.65.1 >/dev/null 2>&1 && cp /e2e/clocks.mjs . && node clocks.mjs; status=\$?; chown -R $(id -u):$(id -g) /out; exit \$status"
    ;;
  e2e-presets)
    # Guided creation on the demo preset, master review and activation (needs: sh scripts/dev.sh demo-reset).
    mkdir -p e2e-output
    docker run --rm --network container:novtt-player-1 -v "$PWD/scripts/e2e":/e2e:ro -v "$PWD/e2e-output":/out \
      mcr.microsoft.com/playwright:v1.65.1-noble sh -c "cd /tmp && npm i -s playwright@1.65.1 >/dev/null 2>&1 && cp /e2e/preset-builder.mjs . && node preset-builder.mjs; status=\$?; chown -R $(id -u):$(id -g) /out; exit \$status"
    ;;
  engine) docker compose --profile test run --build --rm engine ;;
  player) docker compose --profile player up player ;;
  infra) require_db_password; docker compose --profile infra up -d postgres redis ;;
  down) docker compose --profile infra --profile player --profile api down ;;
  logs) docker compose --profile infra --profile player --profile api logs -f ;;
  *) echo "Uso: sh scripts/dev.sh {setup|test|engine|api|api-test|player|demo|demo-reset|e2e|e2e-atmosphere|e2e-presets|infra|down|logs}"; exit 2 ;;
esac

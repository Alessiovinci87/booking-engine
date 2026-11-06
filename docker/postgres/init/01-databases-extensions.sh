#!/usr/bin/env bash
# Crea i database di test e dei test E2E ed abilita btree_gist su tutti (02, "Vincoli di inventario").
#
# Eseguito automaticamente da docker-entrypoint-initdb.d al primo avvio del volume.
# In CI viene eseguito con le stesse variabili, puntando al servizio tramite PGHOST/PGPORT/PGPASSWORD.
set -euo pipefail

: "${POSTGRES_USER:?POSTGRES_USER mancante}"
: "${POSTGRES_DB:?POSTGRES_DB mancante}"
: "${APP_TEST_DATABASE:?APP_TEST_DATABASE mancante}"
# Database dei test E2E (Playwright): separato da quello di sviluppo, che npm run test:e2e non tocca.
APP_E2E_DATABASE="${APP_E2E_DATABASE:-booking_engine_e2e}"

PSQL=(psql -v ON_ERROR_STOP=1 --no-psqlrc --username "$POSTGRES_USER")

# Database di test ed E2E (idempotente).
for db in "$APP_TEST_DATABASE" "$APP_E2E_DATABASE"; do
  if ! "${PSQL[@]}" --dbname "$POSTGRES_DB" -tAc "SELECT 1 FROM pg_database WHERE datname = '${db}'" | grep -q 1; then
    "${PSQL[@]}" --dbname "$POSTGRES_DB" -c "CREATE DATABASE \"${db}\""
  fi
done

for db in "$POSTGRES_DB" "$APP_TEST_DATABASE" "$APP_E2E_DATABASE"; do
  "${PSQL[@]}" --dbname "$db" -c "CREATE EXTENSION IF NOT EXISTS btree_gist"
done

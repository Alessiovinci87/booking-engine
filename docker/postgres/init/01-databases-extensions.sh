#!/usr/bin/env bash
# Crea il database di test ed abilita btree_gist su entrambi i database (02, "Vincoli di inventario").
#
# Eseguito automaticamente da docker-entrypoint-initdb.d al primo avvio del volume.
# In CI viene eseguito con le stesse variabili, puntando al servizio tramite PGHOST/PGPORT/PGPASSWORD.
set -euo pipefail

: "${POSTGRES_USER:?POSTGRES_USER mancante}"
: "${POSTGRES_DB:?POSTGRES_DB mancante}"
: "${APP_TEST_DATABASE:?APP_TEST_DATABASE mancante}"

PSQL=(psql -v ON_ERROR_STOP=1 --no-psqlrc --username "$POSTGRES_USER")

# Database di test (idempotente).
if ! "${PSQL[@]}" --dbname "$POSTGRES_DB" -tAc "SELECT 1 FROM pg_database WHERE datname = '${APP_TEST_DATABASE}'" | grep -q 1; then
  "${PSQL[@]}" --dbname "$POSTGRES_DB" -c "CREATE DATABASE \"${APP_TEST_DATABASE}\""
fi

for db in "$POSTGRES_DB" "$APP_TEST_DATABASE"; do
  "${PSQL[@]}" --dbname "$db" -c "CREATE EXTENSION IF NOT EXISTS btree_gist"
done

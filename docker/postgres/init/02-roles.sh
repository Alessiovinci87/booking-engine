#!/usr/bin/env bash
# Ruoli PostgreSQL dell'applicazione (08, "Livello database (RLS)").
#
#   app_migrator  proprietario dei database, dello schema public e di tutte le tabelle; solo migration
#   app_runtime   applicazione: NOSUPERUSER, NOBYPASSRLS, solo DML concesso con ALTER DEFAULT PRIVILEGES
#   app_admin_ops dispatcher dei job cross-tenant: BYPASSRLS, sola lettura
#   app_retention job di conservazione e cancellazione dei dati all'uscita del cliente (7.1, 7.2):
#                 NOBYPASSRLS, SELECT e DELETE solo sulle tabelle concesse dalle migration (ADR-0009)
#
# APP_RETENTION_SET_ROLE_FOR_TESTS=1 (solo sviluppo locale e CI, mai in staging o produzione) permette ad
# app_runtime di eseguire SET ROLE app_retention senza ereditarne i privilegi: i test lo usano dentro la
# transazione di RefreshDatabase, dove una connessione separata non vedrebbe i dati (ADR-0018).
#
# Idempotente: crea i ruoli se mancano e riallinea attributi, password e privilegi.
# Eseguito da docker-entrypoint-initdb.d al primo avvio del volume e, in CI, con PGHOST/PGPORT/PGPASSWORD.
# Le password arrivano solo da variabili d'ambiente (in locale da .env tramite docker-compose.yml).
set -euo pipefail

: "${POSTGRES_USER:?POSTGRES_USER mancante}"
: "${POSTGRES_DB:?POSTGRES_DB mancante}"
: "${APP_TEST_DATABASE:?APP_TEST_DATABASE mancante}"
APP_E2E_DATABASE="${APP_E2E_DATABASE:-booking_engine_e2e}"
: "${APP_MIGRATOR_PASSWORD:?APP_MIGRATOR_PASSWORD mancante (DB_MIGRATOR_PASSWORD in .env)}"
: "${APP_RUNTIME_PASSWORD:?APP_RUNTIME_PASSWORD mancante (DB_PASSWORD in .env)}"
: "${APP_ADMIN_OPS_PASSWORD:?APP_ADMIN_OPS_PASSWORD mancante (DB_ADMIN_OPS_PASSWORD in .env)}"
: "${APP_RETENTION_PASSWORD:?APP_RETENTION_PASSWORD mancante (DB_RETENTION_PASSWORD in .env)}"
APP_RETENTION_SET_ROLE_FOR_TESTS="${APP_RETENTION_SET_ROLE_FOR_TESTS:-0}"

PSQL=(psql -v ON_ERROR_STOP=1 --no-psqlrc --username "$POSTGRES_USER")

# Ruoli (oggetti globali del cluster). Le password passano come variabili psql (:'nome'),
# che psql cita come letterali SQL: nessuna interpolazione nella stringa del comando.
"${PSQL[@]}" --dbname "$POSTGRES_DB" \
  -v migrator_pw="$APP_MIGRATOR_PASSWORD" \
  -v runtime_pw="$APP_RUNTIME_PASSWORD" \
  -v admin_ops_pw="$APP_ADMIN_OPS_PASSWORD" \
  -v retention_pw="$APP_RETENTION_PASSWORD" <<'SQL'
SELECT 'CREATE ROLE app_migrator'  WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_migrator')  \gexec
SELECT 'CREATE ROLE app_runtime'   WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime')   \gexec
SELECT 'CREATE ROLE app_admin_ops' WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_admin_ops') \gexec
SELECT 'CREATE ROLE app_retention' WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_retention') \gexec

ALTER ROLE app_migrator  LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'migrator_pw';
ALTER ROLE app_runtime   LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'runtime_pw';
ALTER ROLE app_admin_ops LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION BYPASSRLS   PASSWORD :'admin_ops_pw';
ALTER ROLE app_retention LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'retention_pw';
SQL

if [ "$APP_RETENTION_SET_ROLE_FOR_TESTS" = "1" ]; then
  "${PSQL[@]}" --dbname "$POSTGRES_DB" -c "GRANT app_retention TO app_runtime WITH INHERIT FALSE, SET TRUE"
else
  "${PSQL[@]}" --dbname "$POSTGRES_DB" -c "REVOKE app_retention FROM app_runtime"
fi

for db in "$POSTGRES_DB" "$APP_TEST_DATABASE" "$APP_E2E_DATABASE"; do
  "${PSQL[@]}" --dbname "$db" -v dbname="$db" <<'SQL'
-- app_migrator possiede database e schema: crea, modifica ed elimina le tabelle (migrate:fresh nei test).
ALTER DATABASE :"dbname" OWNER TO app_migrator;
ALTER SCHEMA public OWNER TO app_migrator;

REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"dbname" TO app_migrator;
GRANT CONNECT ON DATABASE :"dbname" TO app_runtime, app_admin_ops, app_retention;
GRANT USAGE ON SCHEMA public TO app_runtime, app_admin_ops, app_retention;

-- app_runtime: solo DML sulle tabelle e uso delle sequenze create da app_migrator; nessun DDL.
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO app_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO app_runtime;

-- app_admin_ops: lettura cross-tenant per selezionare gli ID da elaborare; le scritture avvengono
-- nei job per singolo tenant con app_runtime (08).
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
  GRANT SELECT ON TABLES TO app_admin_ops;
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
  GRANT EXECUTE ON FUNCTIONS TO app_admin_ops;

-- Riallinea eventuali oggetti già esistenti (esecuzione ripetuta dello script).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_runtime;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_runtime;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_admin_ops;
SQL
done

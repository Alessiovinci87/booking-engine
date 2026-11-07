# 08 — Sicurezza e operatività

Versione: 1.4 · Data: 07/11/2026 · Modifiche: ADR-0005, [CHANGELOG-v1.1](CHANGELOG-v1.1.md); 1.2: ADR-0008, ADR-0009; 1.3: ADR-0013 (SSRF: solo porta 443, nomi riservati, tutti gli indirizzi risolti pubblici, reti bloccate aggiuntive, `CURLOPT_RESOLVE`; token dei feed e dei preventivi cercati per hash con funzioni SECURITY DEFINER); 1.4: ADR-0016 (gestione password del gestore)

> Le precisazioni di sicurezza di [ADR-0013](adr/0013-precisazioni-m5-calendari-preventivi.md) (punti 1–6, 24, 36) valgono come parte di questo documento.

## Multi-tenancy: due livelli

### Livello applicativo
- Ogni tabella tenant-scoped ha `tenant_id NOT NULL` e indice che lo include.
- Il tenant si ricava **solo** da: membership dell'utente autenticato, slug/token pubblico, account Stripe del webhook, record padre già verificato nei job. Mai dal body o dalla query del client.
- Policy Laravel su ogni modello; global scope Eloquent per `tenant_id` come difesa aggiuntiva, non come unica barriera.
- Risorsa di altro tenant → `404`, mai `403`.

### Livello database (RLS)
- Ruoli PostgreSQL separati:
  - `app_migrator`: proprietario delle tabelle, usato solo dalle migration
  - `app_runtime`: usato dall'applicazione, **non** proprietario, **senza** `BYPASSRLS`
  - `app_admin_ops`: per operazioni globali (job di sistema cross-tenant, supporto), con accesso auditato
- Su ogni tabella tenant-scoped: `ENABLE ROW LEVEL SECURITY` e `FORCE ROW LEVEL SECURITY`.
- Policy fail-closed:

```sql
CREATE FUNCTION app_current_tenant() RETURNS uuid
LANGUAGE sql STABLE AS $$
  SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid
$$;

CREATE POLICY tenant_isolation ON reservations
  USING (tenant_id = app_current_tenant())
  WITH CHECK (tenant_id = app_current_tenant());
```

  Se `app.tenant_id` non è impostato, `app_current_tenant()` è `NULL` e nessuna riga è visibile o scrivibile. Un valore non UUID solleva errore e la transazione fallisce.

- Il tenant si imposta con `SET LOCAL app.tenant_id = …` **all'inizio di ogni transazione**, tramite un unico servizio (`TenantContext::run($tenantId, fn)`). Vietato `SET` di sessione: con connection pooling resterebbe su connessioni riutilizzate.
- Middleware HTTP: apre la transazione con il contesto tenant per ogni richiesta admin e pubblica. Job in coda: ogni job serializza `tenant_id` verificato e lo imposta all'avvio.
- Job cross-tenant (polling iCal, riconciliazione, scadenze): un dispatcher con `app_admin_ops` seleziona gli ID da elaborare, poi ogni elemento viene elaborato in un job separato con `app_runtime` e contesto tenant.

### Integrità tra tenant nel database (ADR-0005)
RLS filtra le righe lette e scritte, ma i controlli delle chiavi esterne in PostgreSQL **ignorano RLS**: una FK semplice accetterebbe un riferimento a una riga di un altro tenant. Per questo tutte le relazioni tra tabelle tenant-scoped usano FK composite `(tenant_id, parent_id) → parent(tenant_id, id)`, con `UNIQUE (tenant_id, id)` su ogni genitore (02). Così il database rifiuta qualsiasi legame trasversale anche se il codice sbaglia (I17).

### Scritture di inventario
Solo `InventoryService` scrive `inventory_occupancies` (02). Un controllo statico in CI fallisce se altre classi eseguono insert o update su quel modello o tabella.

### Test di isolamento (CI obbligatoria)
- Per ogni endpoint admin: richiesta con utente del tenant A su risorsa del tenant B → `404`.
- Per ogni tabella tenant-scoped: query diretta con `app_runtime` senza contesto → 0 righe; con contesto A → solo righe di A; insert con `tenant_id` di B sotto contesto A → errore.
- Test che fallisce se una nuova tabella con `tenant_id` non ha RLS abilitata e forzata (controllo su `pg_class.relrowsecurity` e `relforcerowsecurity`).
- Test che fallisce se una FK tra due tabelle con `tenant_id` non include `tenant_id` (controllo su `pg_constraint`).
- Test che tenta di inserire, sotto il contesto del tenant A, una prenotazione con `unit_id` del tenant B → errore di FK.

## Autenticazione e accesso

- Pannello: Laravel Sanctum con cookie SPA, `SameSite=Lax`, `Secure`, `HttpOnly`; CSRF attivo (token mancante o scaduto → `419 CSRF_TOKEN_EXPIRED`, ADR-0008). Rotte di accesso sotto `/api/v1/admin/auth/*` (07).
- Password: minimo 12 caratteri, controllo contro liste di password compromesse (Have I Been Pwned, solo il prefisso di 5 caratteri dello SHA-1; fail-open con avviso nel log a ogni mancata risposta, senza password né email; ADR-0009), hash Argon2id.
- **MFA obbligatoria** (TOTP) per ogni utente del pannello, con 10 codici di recupero monouso salvati come hash (`user_recovery_codes`, ADR-0008). Un codice TOTP già usato non vale una seconda volta. Nessuna operazione admin senza MFA verificata nella sessione; la configurazione è possibile solo finché la MFA non è attiva.
- Password del gestore ([ADR-0016](adr/0016-gestione-password-gestore.md)): cambio dal pannello con la password attuale e la MFA verificata; "password dimenticata" con risposta sempre identica, link monouso di 60 minuti salvato come hash (token nel frammento dell'indirizzo), 3 richieste/ora per email e 10 per IP; la reimpostazione non apre la sessione e la MFA resta obbligatoria. Cambio e reimpostazione chiudono le altre sessioni (`users.sessions_revoked_at`, confronto al microsecondo) e inviano un'email di avviso.
- Sessione: scadenza per inattività 2 h, assoluta 12 h; ri-autenticazione per azioni sensibili (rimborsi: cancellazione con rimborso > 0, "Riprova", "Segna come rimborsato", ADR-0012 punto 24; cambio account Stripe, export completo (anche l'export CSV delle prenotazioni e l'export dei dati di un ospite, ADR-0014 punto 25), cambio email, anonimizzazione dell'ospite ADR-0014), valida 15 minuti.
- Ruoli: `OWNER` attivo nella 1.0; `MANAGER` e `STAFF` definiti nelle policy ma non assegnabili da UI.
- Accesso di supporto al tenant di un cliente ([ADR-0018](adr/0018-precisazioni-m7-completamento.md) punti 19–25): operatore di piattaforma (`users.is_platform_operator`) con MFA, autorizzazione a tempo creata da `support:open` (motivazione obbligatoria, massimo 2 ore), pannello in sola lettura imposto dal server, banner "Accesso di supporto", revocabile dal gestore, `AuditLog` e pagina "Accessi di supporto" visibili al cliente. Con `app_runtime` e il tenant dell'autorizzazione, non con `app_admin_ops`: RLS resta attiva.

## Token pubblici

- Token casuali di 32 byte (base64url), salvati **solo come hash** (SHA-256) tranne i token dei feed iCal, che servono in chiaro per mostrare l'URL al gestore (cifrati a riposo, con hash per la ricerca).
- Link prenotazione ospite: scadenza 90 giorni dopo il check-out (poi `404`, ADR-0014 punto 26).
- Link preventivo: scadenza = `Quote.expires_at`.
- Nessun token Stripe o segreto negli URL; `Referrer-Policy: strict-origin-when-cross-origin`.

## Protezione dell'import iCal (SSRF)

Vedi 06. Implementazione in un unico client HTTP dedicato (`SafeHttpFetcher`) usato solo per URL forniti dagli utenti: allowlist di schema, risoluzione DNS controllata, blocco reti private/riservate, ricontrollo dopo redirect, limite dimensione e tempo, nessun header di autenticazione interno, egress dal solo worker di sync.

## Dati e cifratura

- In transito: TLS 1.2+ ovunque, HSTS sul dominio pubblico (`Strict-Transport-Security` dall'applicazione sulle risposte https; dietro un load balancer serve `TRUSTED_PROXIES`, ADR-0014 punti 27–28).
- A riposo: dischi cifrati dal provider; cifratura applicativa (Laravel `encrypted` cast) per URL iCal importati, segreti MFA, eventuali token di terze parti.
- Chiavi applicative e segreti (Stripe, email, database) solo in variabili d'ambiente del server o secret manager; mai nel repository. Rotazione documentata.
- Dati di carta: **mai** sui nostri server. Tutto passa da Stripe Checkout.
- Log applicativi: nessun dato personale dell'ospite oltre all'ID; email mascherate; payload webhook non salvati integralmente (solo ID, tipo, hash).

## Header e frontend

- Pagina ospite e pannello: CSP restrittiva (`default-src 'self'`, Stripe solo dove serve), `X-Content-Type-Options: nosniff`.
- Pannello: `frame-ancestors 'none'`.
- Vista embed (iframe): `frame-ancestors` consentito solo ai domini registrati dal gestore per quella struttura; nessun cookie di sessione nell'iframe; il pagamento si apre sempre top-level.

## Audit log

Scritto per: modifiche a struttura, unità, tariffe, stagioni, politiche; ogni transizione di stato di prenotazioni, pagamenti, preventivi, conflitti; rimborsi; blocchi; calendari esterni; accessi e fallimenti di login; accessi di supporto; export di dati. Append-only (revoca di `UPDATE`/`DELETE` ad `app_runtime`), conservato 24 mesi; la cancellazione a scadenza usa solo il ruolo `app_retention` (11, attività 7.2). Gli eventi senza tenant (es. login con email inesistente) hanno `tenant_id` NULL (ADR-0008). Anche `payment_events` è append-only nel database (ADR-0009).

## Backup e ripristino

| Elemento | Politica |
|---|---|
| Database | snapshot giornaliero + WAL continuo (point-in-time recovery 7 giorni) |
| Conservazione | 30 giornalieri, 12 mensili |
| Cifratura | backup cifrati, chiavi separate dai dati |
| Copia | in una seconda regione UE, altro provider o bucket con account separato |
| Storage foto | versioning attivo, 30 giorni |
| Test di ripristino | mensile, automatizzato, in ambiente isolato; esito registrato (invariante I10) |
| Obiettivi | RPO ≤ 15 min, RTO ≤ 4 h |

## Monitoraggio e allerte

| Segnale | Soglia | Canale |
|---|---|---|
| Errori applicativi (Sentry) | nuovo tipo o > 10/min | email + notifica push |
| Coda Horizon in ritardo | job in attesa > 5 min | push |
| Webhook Stripe falliti | ≥ 3 consecutivi | push |
| Prenotazione in `CAPTURING` | > 1 h | push + email |
| Prenotazione in `CAPTURE_FAILED` | all'ingresso e a 12 h | email al gestore |
| Occupazione scaduta non rilasciata | > 30 min | push |
| Incoerenza stato/occupazione (I15) | qualsiasi | push, incidente P1 |
| Rimborso `FAILED` dopo 3 tentativi | qualsiasi | email al gestore |
| Rimborso riuscito dichiarato fallito da Stripe (`SUCCEEDED → FAILED`, ADR-0012 punto 23) | urgente | log `critical` ed email al gestore; nessuna email all'ospite |
| Feed iCal non letto dall'OTA | > 24 h | avviso nel pannello |
| Calendario `UNHEALTHY` | per tenant | email al gestore; riepilogo giornaliero interno |
| Conflitto aperto | qualsiasi | email immediata al gestore |
| Backup o test di ripristino falliti | qualsiasi | push |
| Uptime pagina ospite | 2 controlli falliti | push |

Fino a 6.4 il canale "push" è sostituito da log ed email agli indirizzi della piattaforma (`ALERT_EMAILS`), una email per segnale ogni 60 minuti (ADR-0014 punti 19–21, `OperationalAlerts`).

Health check: `GET /up` (applicazione, database, Redis, coda).

## Ambienti

- `local`, `staging`, `production`, completamente separati (database, Redis, chiavi Stripe test/live, dominio email).
- Staging usa solo Stripe in modalità test e dati sintetici. Mai copie di produzione con dati reali di ospiti.
- Deploy da CI (GitHub Actions) dopo test verdi; migration eseguite con `app_migrator`; rollback documentato.
- Dipendenze: aggiornamenti di sicurezza settimanali (Dependabot), `composer audit` e `npm audit` in CI.

## Gestione incidenti

Classi:

| Classe | Esempi | Presa in carico |
|---|---|---|
| P1 | doppia prenotazione reale, pagamento confermato senza prenotazione, accesso tra tenant, data breach | entro 1 h nelle fasce di supporto, riepilogo scritto entro 24 h |
| P2 | sync fermo per più strutture, webhook non elaborati, email non inviate | entro 4 h lavorative |
| P3 | errori di interfaccia, richieste di modifica | entro 2 giorni lavorativi |

Procedura P1: contenimento (sospendere vendite dell'unità o della funzione), comunicazione al gestore, analisi su audit log, correzione, ADR o post-mortem con test di regressione. Per i data breach vale anche la procedura di 09.

Fasce di supporto e responsabilità contrattuali vanno definite **prima della vendita** nelle condizioni di servizio. Nessuna promessa di assistenza 24/7 nella 1.0.

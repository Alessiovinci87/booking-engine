# 02 — Domain model

Versione: 1.2 · Data: 06/11/2026 · Modifiche rispetto alla 1.0: ADR-0002, ADR-0004, ADR-0005, [CHANGELOG-v1.1](CHANGELOG-v1.1.md); 1.2: ADR-0008, ADR-0009

Tutte le tabelle tenant-scoped hanno `tenant_id UUID NOT NULL`, sono protette da RLS (08) e collegate ai genitori con **chiavi esterne composite** (vedi "Integrità tra tenant"). Colonne comuni non ripetute: `id UUID PK (v7)`, `created_at`, `updated_at` (`TIMESTAMPTZ`).

## Mappa delle entità

```
Tenant ─┬─ TenantMember ── User
        ├─ StripeAccount (1:1)
        └─ Property ─┬─ PropertyTranslation
                     ├─ CancellationPolicy
                     └─ Unit ─┬─ UnitTranslation, UnitPhoto
                              ├─ RatePlan ── SeasonRule
                              ├─ IcsFeed (export, uno per destinazione)
                              ├─ ExternalCalendar ─┬─ ExternalCalendarEvent
                              │                    └─ SyncRun
                              ├─ InventoryOccupancy  ◀── unica fonte delle occupazioni interne
                              ├─ InventoryConflict
                              └─ Reservation ─┬─ ReservationPriceLine
                                              ├─ ReservationChange
                                              ├─ Payment ── PaymentEvent
                                              ├─ Refund
                                              └─ Guest
Quote ──(tentativi)── Reservation
AuditLog · WebhookEvent · OutboxMessage · RetentionPolicy (trasversali)
```

## Entità

### Tenant
`name`, `slug` (univoco), `status` (`ACTIVE`, `SUSPENDED`, `OFFBOARDING`, `CLOSED`), `plan` (`STARTER`, `PLUS`, `PRO`), `default_locale`, `timezone`, `dpa_signed_at`, `dpa_version`, `embed_allowed_origins TEXT[]`, `offboarding_started_at`, `channels_disconnected_confirmed_at`, `feeds_disabled_at`.
Non ha `tenant_id`; RLS la limita a `id = app.tenant_id`.

### User / TenantMember
`User`: `email` (univoco, case-insensitive), `password_hash`, `mfa_secret_encrypted`, `mfa_enabled_at`, `locale`.
`TenantMember`: `tenant_id`, `user_id`, `role` (`OWNER`, `MANAGER`, `STAFF`). Nella 1.0 si crea solo `OWNER`. Unicità `(tenant_id, user_id)`.
`UserRecoveryCode` (globale, come `User`; ADR-0008): `user_id`, `code_hash` (HMAC-SHA256 con la chiave dell'applicazione), `used_at`. 10 codici MFA monouso per utente.

### StripeAccount
`tenant_id` (unico), `stripe_account_id` (unico globale), `charges_enabled`, `payouts_enabled`, `details_submitted`, `livemode`, `last_synced_at`.

### Property
`name`, `slug` (unico per tenant), indirizzo strutturato, `cin_code`, `timezone`, `contact_email`, `contact_phone`, `checkin_time`, `checkout_time`, `privacy_policy_url`, `terms_url`, `ota_commission_bps` (default 1500), `default_cancellation_policy_id` (nullable nel database, obbligatoria per pubblicare; ADR-0009), `status` (`DRAFT`, `PUBLISHED`, `ARCHIVED`).

### PropertyTranslation / UnitTranslation
`locale` (`it`, `en`), `name`, `description`, `house_rules`. Unicità `(parent_id, locale)`. Nessuna traduzione automatica; fallback su `it` con etichetta esplicita.

### Unit
`property_id`, `name`, `base_occupancy`, `max_occupancy`, `booking_mode` (`REQUEST` | `INSTANT`), `payment_mode` (`DEPOSIT_30` | `FULL`), `min_nights`, `cleaning_fee_cents`, `extra_guest_fee_cents`, `sync_health` (`HEALTHY`, `WARNING`, `UNHEALTHY`, `NONE`), `sales_suspended` (bool, interruttore del gestore), `cancellation_policy_id` (facoltativa; ADR-0009), `status` (`ACTIVE`, `INACTIVE`).

Vincolo: `booking_mode = INSTANT` vietato se esiste un `ExternalCalendar` attivo sull'unità (applicazione + trigger).

### RatePlan / SeasonRule
Invariati (04).

### CancellationPolicy
`property_id`, `code` (`FLEXIBLE_7D`, `NON_REFUNDABLE`), unico per struttura. Testi IT/EN standard per codice, nei file di lingua e non modificabili dal gestore nella 1.0 (ADR-0009). Snapshot copiato nella prenotazione.
Politica effettiva di un'unità: `units.cancellation_policy_id` se presente, altrimenti `properties.default_cancellation_policy_id`. Entrambe puntano a una politica **della stessa struttura** (FK composita `(tenant_id, property_id, policy_id)`).

### Guest
`first_name`, `last_name`, `email`, `phone`, `country_code`, `locale`, `anonymized_at`. Un record per prenotazione.

### InventoryOccupancy — unica fonte delle occupazioni interne

Sostituisce `InventoryHold` e `InventoryBlock` della 1.0.

| Campo | Note |
|---|---|
| `unit_id` | |
| `stay DATERANGE` | `[check_in, check_out)` |
| `kind` | `RESERVATION` \| `MANUAL_BLOCK` |
| `phase` | `TEMPORARY` (in attesa di pagamento/decisione) \| `FIRM` |
| `temp_reason` | `CHECKOUT`, `AWAITING_APPROVAL`, `CAPTURING`, `CAPTURE_FAILED`, `PAYMENT_RECOVERY`; null se `FIRM` |
| `expires_at` | scadenza della fase temporanea; null = nessuna scadenza per tempo |
| `reservation_id` | unico; obbligatorio se `kind = RESERVATION` |
| `status` | `ACTIVE` \| `RELEASED` |
| `released_at`, `release_reason` | |
| `ics_uid` | stabile per tutta la vita: `occ-{id}@{dominio}` |
| `note` | solo per `MANUAL_BLOCK` |

Regole:
- **Una sola occupazione per prenotazione**, per tutta la sua vita. Il passaggio da temporanea a definitiva aggiorna la stessa riga (`phase`, `temp_reason`, `expires_at`): l'UID esportato non cambia.
- Una modifica di date aggiorna `stay` sulla stessa riga, tramite la stessa funzione transazionale.
- `expires_at` scaduto **non** libera da solo: l'occupazione resta `ACTIVE` finché il job di rilascio non la chiude dopo aver chiuso o verificato la sessione di pagamento (05). Un'occupazione scaduta ma non rilasciata continua a bloccare le date: è una scelta prudente.
- `ExternalCalendarEvent` e `InventoryConflict` **non** sono occupazioni interne: sono fatti esterni o segnalazioni e possono sovrapporsi tra loro e alle occupazioni.

### Reservation
`unit_id`, `guest_id`, `quote_id` (nullable), `code` (pubblico, 8 caratteri, univoco), `channel` (`DIRECT_WEB`, `QUOTE`, `MANUAL`), `stay DATERANGE`, `adults`, `children`, `status` (03), `confirmation_kind` (`ONLINE_PAYMENT` \| `ADMINISTRATIVE`, valorizzato alla conferma), `booking_mode_snapshot`, `payment_mode_snapshot`, `cancellation_policy_snapshot JSONB`, `contract_total_cents`, `deposit_due_cents`, `balance_due_cents`, `currency`, `guest_locale`, `approval_deadline`, `decision_deadline` (dopo capture fallita), `recovery_deadline`, `confirmed_at`, `cancelled_at`, `cancel_reason`, `public_access_token_hash`, `version` (concorrenza ottimistica).

Stati **derivati** (non memorizzati, calcolati da `Payment` e `Refund`):
- `economic_status`: `UNPAID`, `AUTHORIZED`, `DEPOSIT_PAID`, `PAID`, `PARTIALLY_REFUNDED`, `REFUNDED`
- `refund_status`: `NONE`, `PENDING`, `SUCCEEDED`, `PARTIALLY_FAILED`, `FAILED`

### ReservationPriceLine, ReservationChange
Invariati rispetto alla 1.0.

### Quote
`unit_id`, `stay`, `adults`, `children`, `guest_name`, `guest_email`, `guest_locale`, `computed_total_cents`, `discount_cents`, `final_total_cents`, `price_lines JSONB` (**prezzo congelato**), `booking_mode_snapshot`, `payment_mode_snapshot`, `expires_at` (default creazione + 48 h), `public_token_hash`, `status` (03), `active_reservation_id` (nullable, unico), `checkout_attempts` (int), `max_checkout_attempts` (default 5), `version`.

### Payment
`reservation_id`, `purpose` (`DEPOSIT`, `FULL`, `BALANCE`, `RECOVERY`, `OFFLINE`), `amount_cents`, `capture_method` (`AUTOMATIC`, `MANUAL`), `status` (03), `stripe_account_id`, `checkout_session_id` (unico), `checkout_expires_at`, `payment_intent_id` (unico), `capture_before`, `captured_cents`, `fee_cents`, `method_offline`, `failure_code`.

### PaymentEvent
Append-only (revoca di `UPDATE`/`DELETE` ad `app_runtime`, ADR-0009): `payment_id`, `type`, `amount_cents`, `stripe_object_id`, `occurred_at`, `payload_hash`.

### Refund
`payment_id`, `amount_cents`, `method` (`STRIPE` \| `OFFLINE`), `reason`, `stripe_refund_id` (unico), `status` (`PENDING`, `SUCCEEDED`, `FAILED`, `CANCELLED`), `attempts`, `next_attempt_at`, `requested_by`, `completed_at`.

### IcsFeed — export per destinazione (ADR-0003)
`unit_id`, `destination` (`BOOKING`, `AIRBNB`, `OTHER`), `paired_external_calendar_id` (nullable: il calendario importato dallo **stesso** canale), `token_encrypted`, `token_hash` (per la ricerca), `status` (`ACTIVE`, `FROZEN`, `RETIRED`), `last_fetched_at`, `last_fetch_user_agent`, `rotated_at`. Unicità `(unit_id, destination)` solo tra i feed `ACTIVE` (ADR-0009): alla rotazione del token il vecchio feed resta `FROZEN` per 7 giorni accanto al nuovo `ACTIVE` (06).

### ExternalCalendar
`unit_id`, `source` (`BOOKING`, `AIRBNB`, `OTHER`), `url_encrypted`, `etag`, `last_modified`, `last_attempt_at`, `last_success_at`, `consecutive_failures`, `status` (`ACTIVE`, `DEGRADED`, `UNHEALTHY`, `DISABLED`), `next_poll_at`.

### ExternalCalendarEvent
`external_calendar_id`, `uid`, `stay`, `summary_redacted`, `content_hash`, `first_seen_at`, `last_seen_at`, `missing_since`, `echo_kind` (`NONE`, `CONFIRMED_ECHO`, `SUSPECTED_ECHO`), `echo_of_ics_uid` (nullable), `status` (`ACTIVE`, `PENDING_REMOVAL`, `REMOVED`). Unicità `(external_calendar_id, uid)`.

### SyncRun
Invariato.

### InventoryConflict
`unit_id`, `stay`, `occupancy_id` (nullable), `external_event_id` (nullable), `other_external_event_id` (nullable), `suspected_echo` (bool), `status` (`OPEN`, `INVESTIGATING`, `RESOLVED`, `IGNORED`), `detected_at`, `resolved_at`, `resolved_by`, `resolution` (`CANCELLED_DIRECT`, `CANCELLED_EXTERNAL`, `FALSE_POSITIVE`, `RELOCATED`, `OTHER`), `resolution_notes`.

### AuditLog
Append-only: `tenant_id` (nullable solo per gli eventi di piattaforma senza tenant, es. login con email inesistente; ADR-0008), `actor_type`, `actor_id`, `action`, `subject_type`, `subject_id`, `changes JSONB`, `ip`, `user_agent`, `occurred_at`.
`changes` **non contiene valori di campi personali**: per i campi marcati personali registra solo il nome del campo e `"[personal]"` (09). Per le modifiche dei modelli: `{ campo: { from, to } }` per i campi ordinari, `"[hidden]"` per i campi nascosti (hash, segreti; ADR-0008).
Sono personali anche i campi di testo libero che possono contenere dati dell'ospite: `reservations.cancel_reason`, `reservation_changes.note`, `inventory_occupancies.note`, `inventory_conflicts.resolution_notes`, `refunds.reason`, `external_calendar_events.summary_redacted`, `guests.notes` (ADR-0009).

### WebhookEvent, OutboxMessage
Come 1.0. `OutboxMessage.payload` viene svuotato 30 giorni dopo l'invio (09).

### RetentionPolicy
Configurazione per categoria di dati (09): `category`, `retention_days`, `action` (`ANONYMIZE`, `DELETE`), `legal_basis_note`, `validated_by`, `validated_at`. Nessuna durata è scritta nel codice.

## Integrità tra tenant (ADR-0005)

Le FK di PostgreSQL vengono verificate **senza RLS**: una FK semplice accetterebbe un `unit_id` di un altro tenant, se il codice lo inserisse per errore. Per impedirlo:

- ogni tabella tenant-scoped ha `UNIQUE (tenant_id, id)`;
- ogni relazione tra tabelle tenant-scoped è una FK composita sul genitore:

```sql
ALTER TABLE units ADD CONSTRAINT units_tenant_id_uniq UNIQUE (tenant_id, id);
ALTER TABLE reservations
  ADD CONSTRAINT reservations_unit_fk
  FOREIGN KEY (tenant_id, unit_id) REFERENCES units (tenant_id, id);
```

- gli indici delle FK includono `tenant_id` come prima colonna;
- eccezioni (tabelle globali): `users`, `webhook_events`, `stripe_accounts.stripe_account_id` come chiave di ricerca.

Un test in CI legge `pg_constraint` e fallisce se una FK tra due tabelle con `tenant_id` non include `tenant_id`.

## Vincoli di inventario

Richiede `btree_gist`.

```sql
-- Garanzia del database: nessuna sovrapposizione tra occupazioni interne attive
ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_no_overlap
  EXCLUDE USING gist (unit_id WITH =, stay WITH &&) WHERE (status = 'ACTIVE');

ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_stay_valid
  CHECK (NOT isempty(stay) AND lower_inc(stay) AND NOT upper_inc(stay));

ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_phase_consistent
  CHECK ((phase = 'FIRM' AND temp_reason IS NULL) OR (phase = 'TEMPORARY' AND temp_reason IS NOT NULL));

CREATE UNIQUE INDEX occupancies_one_per_reservation
  ON inventory_occupancies (reservation_id) WHERE reservation_id IS NOT NULL;
```

Il vincolo sulle prenotazioni della 1.0 viene rimosso: la garanzia ora sta in **una sola tabella**.

### Funzione unica di scrittura dell'inventario

Tutte le scritture che creano, estendono, spostano o rilasciano occupazioni passano da `InventoryService`, e **solo** da lì. È vietato scrivere `inventory_occupancies` da altri punti del codice (controllo statico in CI).

```
InventoryService::acquire(unit, stay, kind, reason, expiresAt, reservationDraft)
  BEGIN;
  SET LOCAL app.tenant_id = :tenant;
  SELECT … FROM units WHERE id = :unit FOR UPDATE;          -- serializza per unità
  verifica: unità ACTIVE, vendite non sospese
  verifica: nessuna sovrapposizione con
            external_calendar_events ACTIVE/PENDING_REMOVAL (qualsiasi echo_kind),
            inventory_conflicts OPEN/INVESTIGATING
  INSERT reservation (se kind = RESERVATION)
  INSERT inventory_occupancy                                 -- il vincolo GiST garantisce il resto
  INSERT audit_log, outbox_message
  COMMIT;
```

Operazioni previste: `acquire`, `promoteToFirm`, `setTemporaryReason`, `move` (modifica date), `release`. Ognuna verifica la coerenza con lo stato della prenotazione (I15).

## Disponibilità effettiva

Una notte `d` di un'unità è **vendibile** se e solo se `d` non è contenuta in:

1. una `InventoryOccupancy` `ACTIVE` (anche se `expires_at` è passato ma non ancora rilasciata)
2. un `ExternalCalendarEvent` `ACTIVE` o `PENDING_REMOVAL`
3. un `InventoryConflict` `OPEN` o `INVESTIGATING`

e inoltre l'unità è `ACTIVE`, `sales_suspended = false`, la struttura `PUBLISHED`, il tenant `ACTIVE` e, se `INSTANT`, `sync_health ≠ UNHEALTHY`.

## Invarianti (diventano test automatici)

| # | Invariante | Stato v1.1 |
|---|---|---|
| I1 | Due occupazioni interne attive non si sovrappongono sulla stessa unità (garanzia del database) | modificato |
| I2 | Una prenotazione non diventa `CONFIRMED` per il solo ritorno del browser da Stripe | invariato |
| I3 | Lo stesso webhook non produce due pagamenti né due conferme | invariato |
| I4 | Un pagamento in ritardo non conferma una prenotazione la cui occupazione è stata rilasciata | invariato |
| I5 | Un utente di un tenant non legge né modifica dati di un altro | invariato |
| I6 | Un errore di sincronizzazione non libera date occupate | invariato |
| I7 | Una cancellazione conserva lo storico degli eventi e delle operazioni economiche | invariato |
| I8 | Un preventivo scaduto non avvia un nuovo checkout | invariato |
| I9 | Un'operazione esterna fallita è ritentabile senza duplicazioni | invariato |
| I10 | Un backup è ripristinabile in un ambiente separato e verificabile | invariato |
| I11 | Una prenotazione online diventa `CONFIRMED` solo dopo incasso riuscito; una prenotazione `MANUAL` può essere confermata amministrativamente con stato economico `UNPAID` | modificato |
| I12 | Un'unità con calendari esterni attivi non può essere `INSTANT` | invariato |
| I13 | La chiusura di un conflitto non libera date ancora occupate da altre fonti | invariato |
| I14 | I prezzi di una prenotazione esistente o di un preventivo emesso non cambiano se cambiano le tariffe | modificato |
| I15 | Ogni prenotazione in stato occupante ha esattamente un'occupazione `ACTIVE`; ogni occupazione `RESERVATION` attiva appartiene a una prenotazione in stato occupante | nuovo |
| I16 | Un'occupazione con sessione di pagamento si rilascia solo dopo che la sessione è chiusa o il suo esito è noto | nuovo |
| I17 | Nessuna riga referenzia una riga di un altro tenant (FK composite) | nuovo |
| I18 | Il feed iCal destinato al canale X non contiene eventi importati dal calendario di X, né echi | nuovo |
| I19 | Un feed iCal disattivato non serve mai un calendario vuoto | nuovo |
| I20 | Una cancellazione non comunica un rimborso come avvenuto finché il `Refund` non è `SUCCEEDED` | nuovo |
| I21 | Un preventivo ha al massimo un tentativo di checkout attivo | nuovo |
| I22 | Dopo anonimizzazione, nessun valore personale dell'ospite resta in tabelle, audit log, outbox o export attivi | nuovo |

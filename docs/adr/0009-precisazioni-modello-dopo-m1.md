# ADR-0009 — Precisazioni del modello dopo la verifica di M1

- **Stato**: Accettata
- **Data**: 2026-11-06
- **Autori**: Alessio (decisioni nella verifica di M1), Claude Code (stesura)
- **Documenti di specifica toccati**: 02-DOMAIN-MODEL, 06-CALENDAR-SYNC, 07-API-CONTRACT, 08-SECURITY-OPERATIONS, 09-PRIVACY-GDPR, 10-TRACEABILITY, 11-MILESTONES

## Contesto

Il rapporto di M1 ha segnalato cinque punti aperti:

- **Contraddizione in 06/02.** 02 voleva un solo feed per unità e destinazione tra quelli non `RETIRED`. 06, però, alla rotazione del token tiene il vecchio feed `FROZEN` per 7 giorni accanto al nuovo `ACTIVE`.
- **Politica di cancellazione dell'unità.** 02 lega le politiche alla struttura, ma nessun campo dice quale politica vale per un'unità.
- **Testi liberi.** Alcuni campi di testo libero, scritti dal gestore o arrivati dalle OTA, possono contenere dati dell'ospite, ma non erano marcati `#[PersonalData]`.
- **`payment_events`.** Era append-only solo a livello applicativo.
- **Controllo delle password compromesse.** Era fail-open senza alcuna traccia delle mancate risposte del servizio.

Nessun dato reale esiste ancora: le migration di M1 si possono correggere direttamente.

## Decisione

**a) Feed iCal: unicità solo tra i feed `ACTIVE`.**
- L'indice è `ics_feeds_one_active_per_destination` su `(tenant_id, unit_id, destination) WHERE status = 'ACTIVE'`.
- Alla rotazione del token il vecchio feed resta `FROZEN` per 7 giorni accanto al nuovo `ACTIVE`, poi passa a `RETIRED` (06).
- Durante l'offboarding tutti i feed sono `FROZEN`: più feed `FROZEN` per la stessa destinazione sono ammessi.

**b) Politica di cancellazione per struttura e unità.**
- **Struttura**: `properties.default_cancellation_policy_id`. Nel database è nullable (la politica si crea dopo la struttura), ma è obbligatoria per pubblicare la struttura: guardia di `POST /admin/properties/{id}/publish` in 07, con il nuovo codice `422 CANCELLATION_POLICY_MISSING` (implementazione in M2).
- **Unità**: `units.cancellation_policy_id`, facoltativa.
- **Stessa struttura**: entrambe puntano a una politica della stessa struttura.
  - FK composite `(tenant_id, id, default_cancellation_policy_id)` e `(tenant_id, property_id, cancellation_policy_id)` → `cancellation_policies (tenant_id, property_id, id)`.
  - Per questo `cancellation_policies` ha anche `UNIQUE (tenant_id, property_id, id)`.
- **Politica effettiva**: quella dell'unità se presente, altrimenti quella della struttura (`App\Domain\Reservations\EffectiveCancellationPolicy`).
- **Testi IT/EN standard** per codice, nei file di lingua `lang/<locale>/cancellation.php`. Non si salvano nel database (colonne `text_it`/`text_en` tolte) e il gestore non li modifica nella 1.0. Lo snapshot nella prenotazione resta (02).

**c) Testi liberi come dati personali.** `#[PersonalData]` su tutti i campi di testo libero che possono contenere dati dell'ospite:

| Tabella.campo | Origine |
|---|---|
| `reservations.cancel_reason` | gestore |
| `reservation_changes.note` | gestore |
| `inventory_occupancies.note` | gestore (blocchi e prenotazioni) |
| `inventory_conflicts.resolution_notes` | gestore |
| `refunds.reason` | gestore |
| `external_calendar_events.summary_redacted` | OTA: anche ridotto, il `SUMMARY` di alcune OTA può contenere il nome dell'ospite |
| `guests.notes` | gestore (già marcato in M1) |

Campi di testo esaminati e **non** marcati:

| Campi | Motivo |
|---|---|
| Testi della struttura e delle unità (`description`, `house_rules`) | contenuti commerciali del gestore |
| Codici ed enum (`failure_code`, `error_code`, `temp_reason`, `release_reason`, `resolution`) | valori controllati, non testo libero |
| `ics_feeds.last_fetch_user_agent` | user agent dell'OTA, non dell'ospite |
| `audit_log.ip`, `audit_log.user_agent` | utenti del pannello, categoria F di 09 |
| `outbox_messages.last_error` | solo la classe dell'eccezione (ADR-0008) |
| `retention_policies.legal_basis_note` | configurazione della piattaforma |

Effetti:
- nell'audit compare solo `"[personal]"` (02);
- all'anonimizzazione dell'ospite questi campi diventano `"[rimosso]"` (09, I22).

**d) `payment_events` append-only nel database.** `REVOKE UPDATE, DELETE, TRUNCATE` ad `app_runtime` e revoca delle scritture ad `app_admin_ops`, come per `audit_log` (migration `2026_11_09_001100_restrict_payment_events_privileges`).

**e) Password compromesse: fail-open con avviso.**
- Se Have I Been Pwned non risponde (errore di connessione o stato HTTP non 2xx), la password resta accettata.
- Ogni mancata risposta, però, produce un log di livello `warning` senza password, hash, prefisso né email (`App\Domain\Tenancy\Auth\PwnedPasswordsVerifier`).
- La classe chiama solo l'URL fisso dell'API range ed è un'eccezione esplicita di `Arch/ExternalHttpTest`, come gli adapter Stripe.

**f) Piano (11).**
- **M2**: gli stati di tenant, struttura e unità, che non sono macchine a stati di 03, si cambiano solo tramite i rispettivi servizi. Il controllo statico ammette quelle classi come eccezione esplicita.
- **M3**: CSP di base per pannello e pagina ospite, con `frame-ancestors` per la vista embed.
- **M7 (7.2)**: ruolo dedicato `app_retention`, con `DELETE` solo sulle tabelle soggette a conservazione, usato solo dal job di conservazione.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Rotazione sulla stessa riga con il vecchio hash in una colonna dedicata | Due token per riga complicano il ciclo di vita `FROZEN`/`RETIRED` e l'ultima lettura per feed |
| Unicità tra i feed non `RETIRED` con eccezione applicativa per la rotazione | Il vincolo nel database non proteggerebbe più il caso normale |
| Testi delle politiche modificabili dal gestore | Fuori dalla 1.0: testi standard, coerenti con le regole di rimborso di 05 |
| Politica solo per struttura | Non permette un'unità non rimborsabile in una struttura flessibile |
| Fail-closed sulle password compromesse | Un servizio esterno non disponibile bloccherebbe la creazione dei tenant |

## Conseguenze

- Migration di M1 corrette in place (`2026_11_09_000200`, `2026_11_09_000400`) e una nuova (`2026_11_09_001100`). Database locale e di test ricreati con `migrate:fresh`.
- Il seeder demo crea le due politiche standard della struttura e imposta `FLEXIBLE_7D` come predefinita.
- `AnonymizationService` (M6/M7) deve trattare anche i nuovi campi; il test I22 li copre tramite `#[PersonalData]`.

## Impatto sui test

- Test aggiunti:
  - `Feature/Database/IcsFeedUniquenessTest`
  - `Feature/Database/CancellationPolicyAssignmentTest`
  - `Feature/Database/PaymentEventsPrivilegesTest`
  - `Feature/Auth/PwnedPasswordsVerifierTest`
- Test modificati:
  - `Feature/AuditLogNoPersonalDataTest`: campi di testo libero, motivo di cancellazione registrato come `"[personal]"`.
  - `Feature/DemoSeederTest`: politiche della struttura.
  - `Arch/ExternalHttpTest`: eccezione `PwnedPasswordsVerifier`.
- Invarianti toccati: I17 (FK composite verso la politica della stessa struttura), I19 (feed mai vuoti, rotazione), I22.

## Aggiornamento della specifica

- [x] Documenti aggiornati con riferimento a questa ADR: 02, 06, 07, 08, 09, 10, 11
- [x] Versione della specifica incrementata (02, 06, 07, 08, 09 → 1.2)

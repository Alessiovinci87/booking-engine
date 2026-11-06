# 10 — Matrice di tracciabilità

Versione: 1.2 · Data: 06/11/2026 · Modifiche: percorsi reali dei test di catalogo, test dei controlli statici, stato dell'implementazione a fine M0

Ogni requisito (criteri di accettazione `AC` da 01, invarianti `I` da 02) è collegato a servizio, tabelle, endpoint e test. I nomi di servizi e test sono quelli da usare nel codice; Claude Code aggiorna questa matrice quando li crea o li rinomina.

Convenzioni dei test: `Unit/…` (Pest, senza database), `Feature/…` (Pest con PostgreSQL reale e RLS), `Stripe/…` (Stripe in modalità test, eseguiti in CI notturna), `E2E/…` (Playwright su staging), `Ops/…` (job programmati di verifica).

## Servizi

| Servizio | Responsabilità | Documento |
|---|---|---|
| `TenantContext` | `SET LOCAL app.tenant_id` per transazione | 08 |
| `InventoryService` | unico scrittore di `inventory_occupancies` | 02 |
| `AvailabilityService` | notti vendibili su tre fonti | 02 |
| `PriceCalculator` | prezzo, caparra, saldo | 04 |
| `ReservationStateMachine` | transizioni T1–T18 | 03 |
| `CheckoutService` | sequenza in tre passi, recupero sessione | 05 |
| `OccupancyReleaseJob` | rilascio con `expire` e verifica sessione | 05 |
| `CapturePaymentJob` | capture con idempotency key | 05 |
| `StripeWebhookHandler` | firma, idempotenza, risoluzione tenant | 05 |
| `PaymentReconciler` | riconciliazione periodica, controllo I15 | 05 |
| `RefundService` | macchina del rimborso, retry, offline | 03, 05 |
| `QuoteService` | stati del preventivo, lock, tentativi | 03 |
| `IcalImportService` | polling, parsing, rimozione in due passi | 06 |
| `EchoClassifier` | `CONFIRMED_ECHO` / `SUSPECTED_ECHO` | 06 |
| `ConflictDetector` | creazione `InventoryConflict` | 06 |
| `IcsFeedBuilder` | contenuto del feed per destinazione | 06 |
| `IcsFeedLifecycle` | `ACTIVE` / `FROZEN` / `RETIRED`, rotazione | 06 |
| `OffboardingService` | uscita del cliente | 06, 09 |
| `RetentionService`, `AnonymizationService` | conservazione e anonimizzazione | 09 |
| `AuditLogger` | audit senza valori personali | 02, 08 |
| `SafeHttpFetcher` | download di URL forniti dagli utenti | 06, 08 |
| `MetricsService` | fatturato diretto, beneficio stimato | 01 |
| `PropertySetupService` | strutture, unità, traduzioni, pubblicazione | 01, 02 |
| `UnitService` | modalità di prenotazione, sospensione vendite (I12) | 02 |
| `ConflictService` | presa in carico e chiusura dei conflitti | 03 |

## Criteri di accettazione

| ID | Requisito | Servizio | Tabelle | Endpoint | Test |
|---|---|---|---|---|---|
| AC1 | Configurazione e pubblicazione della struttura IT/EN | `PropertySetupService` | `properties`, `property_translations`, `units`, `unit_translations`, `rate_plans`, `season_rules` | `PATCH /admin/properties/{id}`, `POST /admin/properties/{id}/publish`, `GET /public/{t}/{p}` | `Feature/PropertyPublishTest`, `E2E/setup-property.spec` |
| AC2 | Prenotazione `INSTANT` pagata e confermata, UID stabile in tutti i feed | `CheckoutService`, `ReservationStateMachine`, `IcsFeedBuilder` | `reservations`, `inventory_occupancies`, `payments`, `ics_feeds` | `POST /public/{t}/{p}/reservations`, `GET /ical/{token}.ics` | `Stripe/InstantBookingFlowTest`, `Feature/IcsUidStabilityTest`, `E2E/instant-booking.spec` |
| AC3 | Richiesta pre-autorizzata, approvata e incassata | `CheckoutService`, `CapturePaymentJob` | `reservations`, `payments`, `inventory_occupancies` | `POST /admin/reservations/{id}/approve` | `Stripe/RequestApproveCaptureTest`, `E2E/request-approve.spec` |
| AC4 | Richiesta rifiutata o scaduta, autorizzazione annullata | `ReservationStateMachine`, `OccupancyReleaseJob` | `reservations`, `payments` | `POST /admin/reservations/{id}/reject` | `Stripe/RequestRejectTest`, `Feature/ApprovalDeadlineTest` |
| AC5 | Checkout contemporanei: una sola occupazione | `InventoryService` | `inventory_occupancies` | `POST /public/{t}/{p}/reservations` | `Feature/ConcurrentAcquireTest` |
| AC6 | Conflitto con prenotazione confermata, nessuna cancellazione | `ConflictDetector` | `inventory_conflicts`, `external_calendar_events` | `GET /admin/conflicts` | `Feature/ConflictOnConfirmedTest` |
| AC7 | Calendario irraggiungibile 3 h: `UNHEALTHY`, nessuna data liberata | `IcalImportService` | `external_calendars`, `sync_runs`, `units` | `GET /admin/external-calendars/{id}/runs` | `Feature/SyncUnhealthyTest` |
| AC8 | Preventivo con sconto accettato; scaduto non pagabile | `QuoteService` | `quotes`, `reservations` | `POST /admin/quotes`, `POST /public/quotes/{token}/accept` | `Feature/QuoteAcceptTest`, `Feature/QuoteExpiredTest` |
| AC9 | Cancellazione: date libere, rimborso avviato e completato separatamente | `ReservationStateMachine`, `RefundService` | `reservations`, `refunds`, `inventory_occupancies`, `outbox_messages` | `POST /admin/reservations/{id}/cancel` | `Stripe/CancelRefundTest`, `Feature/RefundEmailTimingTest` |
| AC10 | Webhook duplicato senza effetti doppi | `StripeWebhookHandler` | `webhook_events`, `payments` | `POST /webhooks/stripe` | `Feature/WebhookIdempotencyTest` |
| AC11 | Isolamento tra tenant via API e via database | `TenantContext`, policy | tutte le tabelle tenant-scoped | tutti `/admin/*` | `Feature/TenantIsolationApiTest`, `Feature/Architecture/RlsCatalogTest` |
| AC12 | Ripristino di un backup verificato | — (operazioni) | — | — | `Ops/BackupRestoreCheck` (mensile) |
| AC13 | Propagazione Booking → feed Airbnb; echi non riesportati | `IcsFeedBuilder`, `EchoClassifier` | `ics_feeds`, `external_calendar_events` | `GET /ical/{token}.ics` | `Feature/FeedMatrixTest`, `Feature/EchoLoopTest` |
| AC14 | Capture fallita: date occupate, recupero, rilascio a 24 h | `ReservationStateMachine`, `CheckoutService` | `reservations`, `payments`, `inventory_occupancies` | `POST /admin/reservations/{id}/recovery-link`, `…/release-dates` | `Stripe/CaptureFailureRecoveryTest`, `Feature/CaptureFailedTimeoutTest` |
| AC15 | Sessione completata scoperta dal job: nessun rilascio | `OccupancyReleaseJob` | `inventory_occupancies`, `payments` | — | `Stripe/ReleaseJobCompletedSessionTest` |
| AC16 | Preventivo: un solo tentativo, prezzo congelato | `QuoteService` | `quotes` | `POST /public/quotes/{token}/accept` | `Feature/QuoteConcurrencyTest`, `Feature/QuoteFrozenPriceTest` |
| AC17 | Manuale senza pagamento, poi pagamento offline | `ReservationStateMachine` | `reservations`, `payments` | `POST /admin/reservations`, `…/offline-payments` | `Feature/ManualUnpaidTest` |
| AC18 | Uscita del cliente: feed mai vuoti, ritiro controllato | `OffboardingService`, `IcsFeedLifecycle` | `tenants`, `ics_feeds` | `/admin/tenant/offboarding/*`, `GET /ical/{token}.ics` | `Feature/OffboardingFeedsTest` |
| AC19 | Rimborso fallito: retry, allerta, chiusura offline | `RefundService` | `refunds` | `POST /admin/refunds/{id}/retry`, `…/mark-offline` | `Feature/RefundRetryTest`, `Stripe/RefundFailureTest` |

## Invarianti

| ID | Invariante (sintesi) | Servizio | Tabelle / vincoli | Endpoint | Test |
|---|---|---|---|---|---|
| I1 | Nessuna sovrapposizione tra occupazioni interne attive | `InventoryService` | `inventory_occupancies` + `occupancies_no_overlap` | prenotazione, blocchi, modifica | `Feature/OccupancyExclusionTest`, `Feature/ConcurrentAcquireTest` |
| I2 | Nessuna conferma dal solo redirect | `StripeWebhookHandler` | `payments` | `GET /public/reservations/{code}` | `Feature/NoConfirmOnRedirectTest` |
| I3 | Webhook idempotenti | `StripeWebhookHandler` | `webhook_events` (unico `provider, event_id`) | `POST /webhooks/stripe` | `Feature/WebhookIdempotencyTest` |
| I4 | Pagamento tardivo non conferma | `ReservationStateMachine` (T7) | `reservations`, `refunds` | — | `Stripe/LatePaymentRefundTest` |
| I5 | Isolamento tra tenant | `TenantContext` | RLS su tutte le tabelle | `/admin/*` | `Feature/TenantIsolationApiTest`, `Feature/Architecture/RlsCatalogTest` |
| I6 | Errore di sync non libera date | `IcalImportService` | `external_calendar_events` | — | `Feature/SyncErrorKeepsDatesTest`, `Feature/AntiWipeSafeguardTest` |
| I7 | Storico conservato alla cancellazione | `ReservationStateMachine`, `AuditLogger` | `audit_log`, `payment_events` | `…/cancel` | `Feature/CancelHistoryTest` |
| I8 | Preventivo scaduto non avvia checkout | `QuoteService` | `quotes` | `…/accept` | `Feature/QuoteExpiredTest` |
| I9 | Retry senza duplicati | `CheckoutService`, `CapturePaymentJob`, `RefundService` | idempotency key | tutte le POST con `Idempotency-Key` | `Stripe/IdempotentRetryTest`, `Feature/IdempotencyKeyTest` |
| I10 | Backup ripristinabile | — | — | — | `Ops/BackupRestoreCheck` |
| I11 | Conferma online solo dopo incasso; manuale amministrativa ammessa | `ReservationStateMachine` | `reservations.confirmation_kind` | `…/reservations` | `Feature/ConfirmationKindTest` |
| I12 | Unità con calendari esterni mai `INSTANT` | `UnitService` + trigger | `units`, `external_calendars` | `PATCH /admin/units/{id}` | `Feature/InstantWithCalendarRejectedTest` |
| I13 | Chiusura conflitto non libera date occupate | `ConflictService` | `inventory_conflicts` | `…/conflicts/{id}/resolve` | `Feature/ConflictCloseKeepsDatesTest` |
| I14 | Prezzi congelati (prenotazioni e preventivi) | `PriceCalculator`, `QuoteService` | `reservation_price_lines`, `quotes.price_lines` | — | `Feature/PriceSnapshotTest`, `Feature/QuoteFrozenPriceTest` |
| I15 | Stato occupante ⇔ una occupazione attiva | `InventoryService`, `ReservationStateMachine`, `PaymentReconciler` | `reservations`, `inventory_occupancies` (indice unico per prenotazione) | — | `Feature/OccupancyConsistencyTest` (dopo ogni transizione) |
| I16 | Rilascio solo a sessione chiusa o verificata | `OccupancyReleaseJob` | `inventory_occupancies`, `payments` | — | `Stripe/ReleaseJobCompletedSessionTest`, `Stripe/CheckoutCrashRecoveryTest` |
| I17 | Nessun riferimento tra tenant | migration | FK composite, `UNIQUE (tenant_id, id)` | — | `Feature/Architecture/CompositeFkCatalogTest`, `Feature/CrossTenantInsertRejectedTest` |
| I18 | Feed per X senza eventi di X né echi | `IcsFeedBuilder`, `EchoClassifier` | `ics_feeds`, `external_calendar_events` | `GET /ical/{token}.ics` | `Feature/FeedMatrixTest`, `Feature/EchoLoopTest` |
| I19 | Feed disattivato mai vuoto | `IcsFeedLifecycle` | `ics_feeds.status` | `GET /ical/{token}.ics` | `Feature/FrozenFeedNeverEmptyTest`, `Feature/RetiredFeed410Test` |
| I20 | Rimborso non comunicato prima dell'esito | `RefundService` | `refunds`, `outbox_messages` | `…/cancel` | `Feature/RefundEmailTimingTest` |
| I21 | Un tentativo attivo per preventivo | `QuoteService` | `quotes.active_reservation_id` (unico) | `…/accept` | `Feature/QuoteConcurrencyTest` |
| I22 | Anonimizzazione su tutte le copie | `AnonymizationService` | `guests`, `reservations`, `quotes`, `audit_log`, `outbox_messages`, export | `POST /admin/guests/{id}/erase` | `Feature/AnonymizationCoverageTest`, `Feature/AuditLogNoPersonalDataTest` |

## Controlli statici in CI

| Controllo | Protegge | Test | Stato |
|---|---|---|---|
| Nessuna scrittura su `inventory_occupancies` fuori da `InventoryService` | I1, I15 | `Arch/InventoryWritesTest` | attivo da M0 (modello e tabella non ancora esistenti) |
| Nessun `update(['status' => …])` sui modelli con macchina a stati | 03 | `Arch/StateTransitionsTest`, `Feature/Domain/ModelsTest` | da M1 (decisione dopo M0): (a) a runtime, trait `GuardsStatusWrites` e builder `StatusGuardedBuilder` su `Reservation`, `Payment`, `Refund`, `Quote`, `InventoryConflict`, `OutboxMessage`: eccezione `IllegalStatusWrite` se `status` viene scritto fuori da una classe in `app/Domain/*/StateMachines`; (b) controllo statico limitato alle scritture fuori dalle StateMachines (`update`, `fill`, `forceFill`, `create` e varianti con chiave `status`, `setAttribute('status')`, `->status =`); validazioni e API Resource consentite; eccezione `InventoryService` per le occupazioni |
| Nessuna chiamata HTTP verso URL utente fuori da `SafeHttpFetcher` | 06, 08 | `Arch/ExternalHttpTest` | attivo da M0; eccezione: `App\Domain\Payments\Stripe` |
| Tabelle con `tenant_id` senza RLS forzata | I5 | `Feature/Architecture/RlsCatalogTest` | attivo da M0 |
| FK tra tabelle tenant-scoped senza `tenant_id` | I17 | `Feature/Architecture/CompositeFkCatalogTest` | attivo da M0 |
| Campi `#[PersonalData]` presenti in log o payload di audit | I22 | `Feature/AuditLogNoPersonalDataTest` | da M1 (1.3, 1.6) |

## Stato dell'implementazione

Aggiornato a fine M0 (06/11/2026). Solo fondamenta: nessuna tabella di dominio, nessun endpoint `/api/v1`.

| Elemento | Classe / file | Test |
|---|---|---|
| `TenantContext` | `App\Domain\Tenancy\TenantContext` | `Feature/Tenancy/TenantContextTest` |
| Funzione `app_current_tenant()` | `database/migrations/2026_11_06_100000_create_app_current_tenant_function.php` | `Feature/Tenancy/TenantContextTest` |
| Middleware tenant delle richieste | `App\Domain\Tenancy\Http\WithTenantContext` (alias `tenant`), `App\Domain\Tenancy\TenantResolver` | `Feature/Tenancy/TenantMiddlewareTest` |
| Contesto tenant dei job | `App\Domain\Tenancy\Jobs\{TenantAwareJob, InteractsWithTenant, SetTenantContextForJob}` | `Feature/Tenancy/TenantJobTest` |
| Ruoli PostgreSQL | `docker/postgres/init/02-roles.sh`, connessioni `pgsql`, `pgsql_migrator`, `pgsql_admin_ops` | `Feature/Database/DatabaseRolesTest` |
| `InventoryService` | `App\Domain\Inventory\InventoryService` (segnaposto fino a 3.1) | `Arch/InventoryWritesTest` |
| `SafeHttpFetcher` | `App\Domain\Calendar\SafeHttpFetcher` (segnaposto fino a 5.1) | `Arch/ExternalHttpTest` |
| Struttura del codice (ADR-0006) | `app/Domain/<Area>`, `app/Http/Controllers/Api/V1/{Admin,Public}` | `Arch/DomainStructureTest` |
| SPA pannello e ospite | `resources/js/panel/main.tsx` su `/app`, `resources/js/guest/main.tsx` su `/p/{t}/{p}` | `Feature/Frontend/SpaRoutesTest` |

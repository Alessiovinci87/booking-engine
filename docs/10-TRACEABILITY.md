# 10 — Matrice di tracciabilità

Versione: 1.7 · Data: 07/11/2026 · Modifiche: servizi, tabelle, endpoint e test di M1; stato dell'implementazione a fine M1; decisioni dopo la verifica di M1 (ADR-0008, ADR-0009); servizi, endpoint e test di M2 e stato a fine M2; 1.6: decisioni dopo la verifica di M2 (ADR-0010 accettata), primo test E2E; 1.7: servizi, endpoint e test di M3 (ADR-0011) e stato a fine M3

Ogni requisito (criteri di accettazione `AC` da 01, invarianti `I` da 02) è collegato a servizio, tabelle, endpoint e test. I nomi di servizi e test sono quelli da usare nel codice; Claude Code aggiorna questa matrice quando li crea o li rinomina.

Convenzioni dei test: `Unit/…` (Pest, senza database), `Feature/…` (Pest con PostgreSQL reale e RLS), `Stripe/…` (Stripe in modalità test, eseguiti in CI notturna), `E2E/…` (Playwright su staging), `Ops/…` (job programmati di verifica).

## Servizi

| Servizio | Responsabilità | Documento |
|---|---|---|
| `TenantContext` | `SET LOCAL app.tenant_id` per transazione | 08 |
| `InventoryService` | unico scrittore di `inventory_occupancies` | 02 |
| `AvailabilityService` | notti vendibili su tre fonti | 02 |
| `PriceCalculator` | prezzo, caparra, saldo (puro: niente database, rete, orologio, configurazione) | 04 |
| `PriceQuoteService` | dati salvati dell'unità → `PriceCalculator` (anteprima e pagina ospite) | 04, 07 |
| `RatePlanService` | prezzo base e stagioni, avvisi di sovrapposizione a parità di priorità | 04, ADR-0010 |
| `ReservationStateMachine` | transizioni T1–T18 | 03 |
| `CheckoutService` | sequenza in tre passi, recupero sessione | 05 |
| `OccupancyReleaseJob` | rilascio con `expire` e verifica sessione | 05 |
| `CapturePaymentJob` | capture con idempotency key | 05 |
| `StripeWebhookReceiver`, `StripeEventProcessor` | firma, idempotenza, risoluzione tenant | 05 |
| `PaymentReconciler` | riconciliazione periodica, controllo I15 | 05 |
| `RefundService` | macchina del rimborso, retry, offline | 03, 05 |
| `StripeAccountService` | account connesso, onboarding ospitato, stato riletto da Stripe | 05, ADR-0012 |
| `WebhookEventStateMachine` | stati del `WebhookEvent` (I3) | 05, ADR-0012 |
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
| `PropertySetupService` | strutture, traduzioni, politiche standard, pubblicazione con le guardie di 07; unico punto che cambia lo stato della struttura | 01, 02, 07, ADR-0009, ADR-0010 |
| `UnitPhotoService` | foto delle unità: ricodifica WebP senza EXIF, ordine, URL firmati | 08, 09, ADR-0006 |
| `PublicCatalog` | ciò che la superficie pubblica vede: struttura `PUBLISHED`, tenant `ACTIVE`, unità `ACTIVE` | 02, 07 |
| `TenantLifecycleService` | stato del tenant (eccezione esplicita di `Arch/StateTransitionsTest`) | 02, ADR-0009 |
| `UnitService` | modalità di prenotazione (I12), sospensione vendite, stato dell'unità, piano tariffario alla creazione | 02 |
| `ConflictService` | presa in carico e chiusura dei conflitti | 03 |
| `LoginService`, `MfaService`, `RecoveryCodes`, `LoginThrottle`, `PanelSession` | accesso al pannello, MFA TOTP, codici di recupero, limiti ai tentativi, durata della sessione | 08, ADR-0008 |
| `PwnedPasswordsVerifier` | password compromesse, fail-open con avviso nel log | 08, ADR-0009 |
| `EffectiveCancellationPolicy` | politica dell'unità, altrimenti quella della struttura | 02, ADR-0009 |
| `TenantProvisioning` | creazione di tenant e proprietario (`tenant:create`) | 01, 08 |
| `Outbox`, `OutboxMessageStateMachine`, `DispatchOutboxJob`, `DeliverOutboxMessageJob` | accodamento con `dedupe_key`, consegna per tenant, retry con backoff | 02, 03, ADR-0008 |

## Criteri di accettazione

| ID | Requisito | Servizio | Tabelle | Endpoint | Test |
|---|---|---|---|---|---|
| AC1 | Configurazione e pubblicazione della struttura IT/EN | `PropertySetupService`, `UnitService`, `RatePlanService`, `UnitPhotoService`, `PublicCatalog` | `properties`, `property_translations`, `units`, `unit_translations`, `unit_photos`, `rate_plans`, `season_rules` | `GET/POST /admin/properties`, `GET/PATCH /admin/properties/{id}`, `POST /admin/properties/{id}/publish`, `/admin/units/*`, `GET /public/{t}/{p}` | `Feature/PropertyPublishTest`, `Feature/Admin/{PropertyApiTest, UnitApiTest, UnitPhotoApiTest, RatePlanApiTest}`, `Feature/Public/PublicPropertyApiTest`, `E2E/m2-panel.spec` (in locale da M2), `E2E/setup-property.spec` (M6) |
| AC2 | Prenotazione `INSTANT` pagata e confermata, UID stabile in tutti i feed | `CheckoutService`, `ReservationStateMachine`, `IcsFeedBuilder` | `reservations`, `inventory_occupancies`, `payments`, `ics_feeds` | `POST /public/{t}/{p}/reservations`, `GET /ical/{token}.ics` | `Stripe/InstantBookingFlowTest`, `Feature/Payments/StripeCheckoutTest`, `Feature/Payments/StripeFlowsTest`, `Feature/IcsUidStabilityTest` (M5), `E2E/m4-stripe-checkout.spec`, `E2E/instant-booking.spec` (M6) |
| AC3 | Richiesta pre-autorizzata, approvata e incassata | `CheckoutService`, `CapturePaymentJob` | `reservations`, `payments`, `inventory_occupancies` | `POST /admin/reservations/{id}/approve` | `Stripe/RequestApproveCaptureTest`, `Feature/Payments/StripeFlowsTest`, `E2E/request-approve.spec` (M6) |
| AC4 | Richiesta rifiutata o scaduta, autorizzazione annullata | `ReservationStateMachine`, `OccupancyReleaseJob` | `reservations`, `payments` | `POST /admin/reservations/{id}/reject` | `Stripe/RequestRejectTest`, `Feature/ApprovalDeadlineTest`, `Feature/Payments/StripeFlowsTest` |
| AC5 | Checkout contemporanei: una sola occupazione | `InventoryService` | `inventory_occupancies` | `POST /public/{t}/{p}/reservations` | `Feature/ConcurrentAcquireTest` |
| AC6 | Conflitto con prenotazione confermata, nessuna cancellazione | `ConflictDetector` | `inventory_conflicts`, `external_calendar_events` | `GET /admin/conflicts` | `Feature/ConflictOnConfirmedTest` |
| AC7 | Calendario irraggiungibile 3 h: `UNHEALTHY`, nessuna data liberata | `IcalImportService` | `external_calendars`, `sync_runs`, `units` | `GET /admin/external-calendars/{id}/runs` | `Feature/SyncUnhealthyTest` |
| AC8 | Preventivo con sconto accettato; scaduto non pagabile | `QuoteService` | `quotes`, `reservations` | `POST /admin/quotes`, `POST /public/quotes/{token}/accept` | `Feature/QuoteAcceptTest`, `Feature/QuoteExpiredTest` |
| AC9 | Cancellazione: date libere, rimborso avviato e completato separatamente | `ReservationStateMachine`, `RefundService` | `reservations`, `refunds`, `inventory_occupancies`, `outbox_messages` | `POST /admin/reservations/{id}/cancel` | `Stripe/CancelRefundTest`, `Feature/RefundEmailTimingTest` |
| AC10 | Webhook duplicato senza effetti doppi | `StripeWebhookReceiver`, `StripeEventProcessor` | `webhook_events`, `payments` | `POST /webhooks/stripe` | `Feature/WebhookIdempotencyTest` |
| AC11 | Isolamento tra tenant via API e via database | `TenantContext`, policy | tutte le tabelle tenant-scoped | tutti `/admin/*` | `Feature/TenantIsolationApiTest`, `Feature/Architecture/RlsCatalogTest` |
| AC12 | Ripristino di un backup verificato | — (operazioni) | — | — | `Ops/BackupRestoreCheck` (mensile) |
| AC13 | Propagazione Booking → feed Airbnb; echi non riesportati | `IcsFeedBuilder`, `EchoClassifier` | `ics_feeds`, `external_calendar_events` | `GET /ical/{token}.ics` | `Feature/FeedMatrixTest`, `Feature/EchoLoopTest` |
| AC14 | Capture fallita: date occupate, recupero, rilascio a 24 h | `ReservationStateMachine`, `CheckoutService` | `reservations`, `payments`, `inventory_occupancies` | `POST /admin/reservations/{id}/recovery-link`, `…/release-dates` | `Stripe/CaptureFailureRecoveryTest`, `Feature/CaptureFailedTimeoutTest` |
| AC15 | Sessione completata scoperta dal job: nessun rilascio | `OccupancyReleaseJob` | `inventory_occupancies`, `payments` | — | `Stripe/ReleaseJobCompletedSessionTest`, `Feature/Payments/StripeReleaseTest` |
| AC16 | Preventivo: un solo tentativo, prezzo congelato | `QuoteService` | `quotes` | `POST /public/quotes/{token}/accept` | `Feature/QuoteConcurrencyTest`, `Feature/QuoteFrozenPriceTest` |
| AC17 | Manuale senza pagamento, poi pagamento offline | `ReservationStateMachine` | `reservations`, `payments` | `POST /admin/reservations`, `…/offline-payments` | `Feature/ManualUnpaidTest` |
| AC18 | Uscita del cliente: feed mai vuoti, ritiro controllato | `OffboardingService`, `IcsFeedLifecycle` | `tenants`, `ics_feeds` | `/admin/tenant/offboarding/*`, `GET /ical/{token}.ics` | `Feature/OffboardingFeedsTest` |
| AC19 | Rimborso fallito: retry, allerta, chiusura offline | `RefundService` | `refunds` | `POST /admin/refunds/{id}/retry`, `…/mark-offline` | `Feature/RefundRetryTest`, `Stripe/RefundFailureTest` |

## Invarianti

| ID | Invariante (sintesi) | Servizio | Tabelle / vincoli | Endpoint | Test |
|---|---|---|---|---|---|
| I1 | Nessuna sovrapposizione tra occupazioni interne attive | `InventoryService` | `inventory_occupancies` + `occupancies_no_overlap` | prenotazione, blocchi, modifica | `Feature/OccupancyExclusionTest`, `Feature/ConcurrentAcquireTest` |
| I2 | Nessuna conferma dal solo redirect | `StripeWebhookReceiver`, `StripeEventProcessor` | `payments` | `GET /public/reservations/{code}` | `Feature/NoConfirmOnRedirectTest`, `Feature/Payments/GuestReturnPageTest` |
| I3 | Webhook idempotenti | `StripeWebhookReceiver`, `StripeEventProcessor` | `webhook_events` (unico `provider, event_id`) | `POST /webhooks/stripe` | `Feature/WebhookIdempotencyTest` |
| I4 | Pagamento tardivo non conferma | `ReservationStateMachine` (T7) | `reservations`, `refunds` | — | `Stripe/LatePaymentRefundTest`, `Feature/Payments/StripeReleaseTest` |
| I5 | Isolamento tra tenant | `TenantContext` | RLS su tutte le tabelle | `/admin/*` | `Feature/TenantIsolationApiTest`, `Feature/Architecture/RlsCatalogTest` |
| I6 | Errore di sync non libera date | `IcalImportService` | `external_calendar_events` | — | `Feature/SyncErrorKeepsDatesTest`, `Feature/AntiWipeSafeguardTest` |
| I7 | Storico conservato alla cancellazione | `ReservationStateMachine`, `AuditLogger` | `audit_log`, `payment_events` | `…/cancel` | `Feature/CancelHistoryTest` |
| I8 | Preventivo scaduto non avvia checkout | `QuoteService` | `quotes` | `…/accept` | `Feature/QuoteExpiredTest` |
| I9 | Retry senza duplicati | `CheckoutService`, `CapturePaymentJob`, `RefundService` | idempotency key | tutte le POST con `Idempotency-Key` | `Stripe/IdempotentRetryTest`, `Feature/IdempotencyKeyTest` |
| I10 | Backup ripristinabile | — | — | — | `Ops/BackupRestoreCheck` |
| I11 | Conferma online solo dopo incasso; manuale amministrativa ammessa | `ReservationStateMachine` | `reservations.confirmation_kind` | `…/reservations` | `Feature/ConfirmationKindTest` |
| I12 | Unità con calendari esterni mai `INSTANT` | `UnitService` + trigger (SQLSTATE `BE012`) | `units`, `external_calendars` | `PATCH /admin/units/{id}` (`409 EXTERNAL_CALENDARS_ACTIVE`) | `Feature/InstantWithCalendarRejectedTest` |
| I13 | Chiusura conflitto non libera date occupate | `ConflictService` | `inventory_conflicts` | `…/conflicts/{id}/resolve` | `Feature/ConflictCloseKeepsDatesTest` |
| I14 | Prezzi congelati (prenotazioni e preventivi) | `PriceCalculator`, `QuoteService` | `reservation_price_lines`, `quotes.price_lines` | — | `Feature/PriceSnapshotTest` (parte prezzi, M2), `Unit/Pricing/PriceCalculatorTest` (golden di 04), `Feature/QuoteFrozenPriceTest` (M5) |
| I15 | Stato occupante ⇔ una occupazione attiva | `InventoryService`, `ReservationStateMachine`, `PaymentReconciler` | `reservations`, `inventory_occupancies` (indice unico per prenotazione) | — | `Feature/OccupancyConsistencyTest` (dopo ogni transizione) |
| I16 | Rilascio solo a sessione chiusa o verificata | `OccupancyReleaseJob` | `inventory_occupancies`, `payments` | — | `Stripe/ReleaseJobCompletedSessionTest`, `Stripe/CheckoutCrashRecoveryTest`, `Feature/Payments/StripeReleaseTest`, `Feature/Payments/PaymentReconciliationTest` |
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
| Nessuna chiamata HTTP verso URL utente fuori da `SafeHttpFetcher` | 06, 08 | `Arch/ExternalHttpTest` | attivo da M0; eccezioni verso URL fissi: `App\Domain\Payments\Stripe`, `App\Domain\Tenancy\Auth\PwnedPasswordsVerifier` (ADR-0009) |
| Tabelle con `tenant_id` senza RLS forzata | I5 | `Feature/Architecture/RlsCatalogTest` | attivo da M0 |
| FK tra tabelle tenant-scoped senza `tenant_id` | I17 | `Feature/Architecture/CompositeFkCatalogTest` | attivo da M0 |
| `PriceCalculator` puro (niente database, facade, orologio, configurazione, rete) | 04, I14 | `Arch/PriceCalculatorPurityTest` | attivo da M2 |
| Campi `#[PersonalData]` presenti in log o payload di audit | I22 | `Feature/AuditLogNoPersonalDataTest` | attivo da M1: tutti i modelli con `#[PersonalData]` (compresi i testi liberi di ADR-0009), modifiche reali di `Guest` e `Reservation`, campi nascosti di `User` |

## Stato dell'implementazione

Aggiornato a fine M4 (07/11/2026): pagamenti Stripe (Connect, checkout, webhook, flussi A, B e R, rilascio, rimborsi, riconciliazione, pannello Pagamenti, suite `Stripe/*` notturna). In precedenza, a fine M3: Tutte le tabelle di 02 con RLS forzata e FK composite; accesso al pannello con MFA; API admin di strutture, unità, foto, tariffe, stagioni, pubblicazione e anteprima prezzo; inventario con `InventoryService`, disponibilità, macchina a stati T1–T18 con gateway di pagamento finto, rilascio delle occupazioni, prenotazioni manuali, pagamenti offline, blocchi, modifica e cancellazione; API pubblica di struttura, disponibilità, prezzo, prenotazione e stato; pagina ospite IT/EN; pannello di configurazione, calendario e prenotazioni. Restano per M4 i test `Stripe/*` e i servizi Stripe reali (`StripeWebhookReceiver`, `StripeEventProcessor`, `PaymentReconciler`).

| Elemento | Classe / file | Test |
|---|---|---|
| `TenantContext` | `App\Domain\Tenancy\TenantContext` | `Feature/Tenancy/TenantContextTest` |
| Funzione `app_current_tenant()` | `database/migrations/2026_11_06_100000_create_app_current_tenant_function.php` | `Feature/Tenancy/TenantContextTest` |
| Middleware tenant delle richieste | `App\Domain\Tenancy\Http\WithTenantContext` (alias `tenant`; `tenant:public` per lo slug), `App\Domain\Tenancy\MembershipTenantResolver` (pannello), `App\Domain\Tenancy\PublicSlugTenantResolver` (pubblica); rollback se il controller fallisce | `Feature/Tenancy/{TenantMiddlewareTest, TenantRequestRollbackTest}`, `Feature/TenantIsolationApiTest`, `Feature/Public/PublicPropertyApiTest` |
| Contesto tenant dei job | `App\Domain\Tenancy\Jobs\{TenantAwareJob, InteractsWithTenant, SetTenantContextForJob}` | `Feature/Tenancy/TenantJobTest` |
| Ruoli PostgreSQL | `docker/postgres/init/02-roles.sh`, connessioni `pgsql`, `pgsql_migrator`, `pgsql_admin_ops` | `Feature/Database/DatabaseRolesTest` |
| Tabelle di 02 (1.1) | `database/migrations/2026_11_09_000100…000800`, helper `App\Domain\Tenancy\Database\TenantTable` | `Feature/Database/SchemaTest`, `Feature/OccupancyExclusionTest`, `Unit/StayRangeTest` |
| RLS e FK composite (1.1, 1.2) | `TenantTable::create` / `enableRowLevelSecurity`, policy `tenant_isolation` | `Feature/Architecture/RlsCatalogTest`, `Feature/Architecture/CompositeFkCatalogTest`, `Feature/CrossTenantInsertRejectedTest`, `Feature/Database/TenantRowIsolationTest` |
| `audit_log` append-only (1.2) | `2026_11_09_001000_restrict_audit_log_privileges.php` | `Feature/Database/AuditLogPrivilegesTest` |
| `payment_events` append-only (ADR-0009) | `2026_11_09_001100_restrict_payment_events_privileges.php` | `Feature/Database/PaymentEventsPrivilegesTest` |
| Un solo feed `ACTIVE` per destinazione (ADR-0009) | indice `ics_feeds_one_active_per_destination` | `Feature/Database/IcsFeedUniquenessTest` |
| Politica di cancellazione di struttura e unità (ADR-0009) | `properties.default_cancellation_policy_id`, `units.cancellation_policy_id`, `App\Domain\Reservations\EffectiveCancellationPolicy`, `lang/<locale>/cancellation.php` | `Feature/Database/CancellationPolicyAssignmentTest` |
| Funzioni `SECURITY DEFINER` | `app_user_tenants(uuid)`, `app_tenant_by_slug(text)` (`2026_11_09_000900`) | `Feature/Database/TenantLookupFunctionsTest` |
| Modelli, enum, `#[PersonalData]` (1.3) | `app/Domain/<Area>/Models`, `app/Domain/<Area>/Enums`, `App\Domain\Privacy\{PersonalData, PersonalDataRegistry}` | `Feature/Domain/ModelsTest` |
| Scritture di `status` solo dalle macchine a stati | `GuardsStatusWrites`, `StatusGuardedBuilder`, `StatusWriteGuard` | `Feature/Domain/ModelsTest`, `Arch/StateTransitionsTest` |
| Accesso al pannello (1.4) | `App\Domain\Tenancy\Auth\*`, middleware `panel.fresh`, `mfa`, `reauth`, `App\Http\Controllers\Api\V1\Admin\Auth\*` | `Feature/Auth/{LoginTest, LoginThrottleTest, MfaTest, SessionExpiryTest, ReauthTest, CsrfTest, PwnedPasswordsVerifierTest}` |
| `GET /api/v1/admin/me` | `MeController` | `Feature/TenantIsolationApiTest` |
| Strutture e unità (2.1) | `Admin\PropertyController`, `Admin\UnitController`, `PropertySetupService`, `UnitService`, `App\Domain\Inventory\Translations`, colonne `version` e `App\Http\Api\IfMatch` (412) | `Feature/Admin/{PropertyApiTest, UnitApiTest}`, `Feature/TenantIsolationApiTest` |
| Foto delle unità (2.2) | `Admin\UnitPhotoController`, `App\Domain\Inventory\Photos\UnitPhotoService`, `config/photos.php` | `Feature/Admin/UnitPhotoApiTest` |
| Tariffe e stagioni (2.3) | `Admin\RatePlanController`, `RatePlanService` | `Feature/Admin/RatePlanApiTest` |
| Calcolo prezzi (2.4) | `App\Domain\Pricing\Calculator\*`, `PriceQuoteService`, `Admin\PricePreviewController`, `PriceQuoteResource` | `Unit/Pricing/PriceCalculatorTest`, `Feature/Admin/PricePreviewApiTest`, `Feature/PriceSnapshotTest`, `Arch/PriceCalculatorPurityTest` |
| I12 e stati di tenant, struttura, unità (2.5) | `UnitService`, `TenantLifecycleService`, `2026_11_23_000300_enforce_instant_without_external_calendars.php` | `Feature/InstantWithCalendarRejectedTest`, `Feature/Admin/UnitSalesTest`, `Arch/StateTransitionsTest` |
| Pubblicazione (2.6) | `PropertySetupService::publish`, `POST /admin/properties/{id}/publish` | `Feature/PropertyPublishTest` |
| API pubblica (2.7) | `Public\PropertyController`, `PublicCatalog`, `App\Domain\Tenancy\PublicSlugTenantResolver` (middleware `tenant:public`), `App\Http\Middleware\SetGuestLocale`, limite `public-read` | `Feature/Public/PublicPropertyApiTest` |
| Pannello: struttura, unità, foto, tariffe, anteprima (2.8) | `resources/js/panel/pages/{PropertiesPage, PropertyPage, UnitPage}.tsx`, `resources/js/panel/pages/unit/*`, `resources/js/panel/api/admin.ts`, `resources/js/panel/money.ts` | `Feature/Frontend/SpaRoutesTest`, `npm run typecheck`, `tests/E2E/m2-panel.spec.ts` (`npm run test:e2e`, solo in locale; in CI da M6) |
| `tenant:create` (1.5) | `App\Console\Commands\CreateTenantCommand`, `App\Domain\Tenancy\TenantProvisioning` | `Feature/Tenancy/CreateTenantCommandTest` |
| `AuditLogger` (1.6) | `App\Domain\Tenancy\Audit\{AuditLogger, AuditChanges}` | `Feature/AuditLogNoPersonalDataTest` |
| Outbox (1.6) | `App\Domain\Tenancy\Outbox\*`, `App\Domain\Tenancy\StateMachines\OutboxMessageStateMachine`, job `DispatchOutboxJob` (ogni minuto) e `DeliverOutboxMessageJob` | `Feature/Tenancy/OutboxTest` |
| Pannello: accesso e layout (1.7) | `resources/js/panel/{api,auth,components,pages}` | `Feature/Frontend/SpaRoutesTest` |
| Seeder demo (1.8, 2.8) | `Database\Seeders\DemoSeeder` (due stagioni per unità, account Stripe finto `acct_demo_…`, mai in produzione) | `Feature/DemoSeederTest` |
| `InventoryService` (3.1) | `App\Domain\Inventory\InventoryService`: `acquireForReservation`, `acquireBlock`, `releaseBlock`, `promoteToFirm`, `setTemporaryReason`, `move`, `release`, `calendarOccupancies`; lock sull'unità, `23P01` → `DATES_UNAVAILABLE` | `Feature/Inventory/InventoryServiceTest`, `Feature/OccupancyExclusionTest`, `Feature/ConcurrentAcquireTest`, `Arch/InventoryWritesTest` |
| Disponibilità (3.2) | `App\Domain\Inventory\AvailabilityService`, `GET /public/{t}/{p}/availability` (`min_nights_by_night`) | `Feature/Public/AvailabilityApiTest` |
| Gateway di pagamento (3.3) | `App\Domain\Payments\Gateway\{PaymentGateway, CheckoutSessionRequest, CheckoutSession, CheckoutSessionState}`, `Fake\FakePaymentGateway` (solo `local`/`testing`), `config/payments.php` | `Feature/Payments/FakePaymentGatewayTest` |
| Checkout simulato (3.3) | `SimulatedCheckoutController`, `resources/views/simulated-checkout.blade.php`, `SimulatedPaymentEvents` (stessi eventi dei webhook), rotte `/_simulated-checkout/*` solo con gateway finto e `local`/`testing` | `Feature/Payments/SimulatedCheckoutTest`, `Feature/NoConfirmOnRedirectTest` |
| `Idempotency-Key` (I9) | `App\Http\Middleware\EnforceIdempotencyKey` (alias `idempotent`), `config/security.php` | `Feature/IdempotencyKeyTest`, `Feature/PropertyPublishTest`, `Feature/ManualUnpaidTest` |
| Macchina a stati della prenotazione (3.4) | `App\Domain\Reservations\StateMachines\{ReservationStateMachine, ReservationTransitions}`, `App\Domain\Payments\StateMachines\{PaymentStateMachine, RefundStateMachine}`, `App\Domain\Payments\{CheckoutService, CheckoutOutcomes, PaymentEventHandler}`, job `CapturePaymentJob`, `CancelAuthorizationJob`, `ProcessRefundJob` | `Feature/Reservations/ReservationTransitionsTest`, `Feature/OccupancyConsistencyTest`, `Feature/ConfirmationKindTest` |
| Email delle prenotazioni (3.4) | messaggi outbox `reservation.*`, `App\Domain\Reservations\Outbox\{SendReservationEmail, ReservationEmail}`, `lang/<locale>/emails.php` | `Feature/Reservations/ReservationTransitionsTest` |
| Prenotazioni del pannello (3.5) | `Admin\ReservationController` (`GET/POST /admin/reservations`, `GET/PATCH /admin/reservations/{id}`, `approve`, `reject`, `cancel`, `release-dates`, `offline-payments`, `recovery-link` con `tenant:panel,steps`), `ReservationEconomics` (stato economico e `refund_status` derivati), `OfflinePaymentService`, `ReservationResource` | `Feature/Admin/ReservationApiTest`, `Feature/ManualUnpaidTest`, `Feature/CancelHistoryTest`, `Feature/PriceSnapshotTest` |
| Blocchi e calendario (3.5) | `Admin\BlockController` (`POST/DELETE /admin/units/{id}/blocks`), `Admin\CalendarController` (`GET /admin/calendar`) | `Feature/Admin/ReservationApiTest` |
| Rilascio delle occupazioni (3.6) | `App\Domain\Reservations\Jobs\{DispatchOccupancyReleaseJob, OccupancyReleaseJob}` (ogni minuto, `occupancies:release`; dispatcher con `app_admin_ops`, un job per tenant con `app_runtime`), `InventoryService::{tenantsWithExpiredTemporary, expiredReservations}`, `CheckoutService::releaseExpired`; T5, T6, T11, T16 | `Feature/Reservations/OccupancyReleaseJobTest` (gateway finto; la suite `Stripe/*` arriva con M4) |
| Prenotazione dalla pagina ospite (3.7) | `Public\ReservationController` (`POST /public/{t}/{p}/reservations` con `tenant:public,steps`, `GET /public/reservations/{code}` con `tenant:reservation`), `PublicReservationRequest`, `LegalAcceptance`, `App\Domain\Tenancy\ReservationAccessTenantResolver`, funzione `app_tenant_by_reservation_access` (`2026_12_07_000100_create_reservation_access_lookup.php`), limite `public-booking`, guardia `PRIVACY_POLICY_MISSING` | `Feature/Public/PublicReservationApiTest`, `Feature/PropertyPublishTest`, `Feature/Architecture/RlsCatalogTest` |
| Sicurezza della pagina ospite (3.7) | `App\Http\Middleware\SecurityHeaders` (`security:page\|embed\|api`, CSP con nonce, `frame-ancestors`), `App\Http\Middleware\CheckPublicOrigin` (`public.origin`), `GuestPageController` (`/p/…` e `/e/…` senza sessione), Sanctum stateful solo su `/admin` | `Feature/SecurityHeadersTest`, `Feature/Public/PublicReservationApiTest`, `Feature/Auth/CsrfTest` |
| Pagina ospite (3.7) | `resources/js/guest/pages/{PropertyPage, BookingPage, ReservationPage}.tsx`, `resources/js/guest/{api, storage, format, components}.ts(x)`, `react-day-picker` (ADR-0006) | `Feature/Frontend/SpaRoutesTest`, `npm run typecheck`, `tests/E2E/m3-guest-booking.spec.ts` |
| Pannello: calendario, prenotazioni, blocchi (3.8) | `resources/js/panel/pages/{CalendarPage, ReservationsPage, ReservationDetailPage, NewReservationPage}.tsx`, `resources/js/panel/api/reservations.ts`, `resources/js/panel/components/Reservations.tsx`, `ReservationResource::detail` (`actions`, `cancellation_refund`) | `Feature/Admin/ReservationApiTest`, `Feature/Frontend/SpaRoutesTest`, `npm run typecheck`, `tests/E2E/m3-guest-booking.spec.ts` |
| Seeder demo con prenotazioni (3.8) | `Database\Seeders\DemoSeeder::reservations` (struttura pubblicata, prenotazioni in sei stati con flussi veri, blocco manuale) | `Feature/DemoSeederTest` |
| Richiesta senza transazione unica | `WithTenantContext` con `tenant:<superficie>,steps` (chiamate al servizio di pagamento fuori transazione, 05) | `Feature/Admin/ReservationApiTest` (link di recupero) |
| Account Stripe connesso (4.1) | `App\Domain\Payments\{StripeAccountService}`, `App\Domain\Payments\Stripe\{StripeClientFactory, StripeConnectedAccounts, StripeErrors}`, `Admin\StripeAccountController` (`GET /admin/stripe/account`, `POST /admin/stripe/onboarding-link` con `reauth`), comando `stripe:demo-account` (`--account`) | `Feature/Payments/StripeConnectOnboardingTest` |
| Gateway Stripe e checkout (4.2) | `App\Domain\Payments\Stripe\StripePaymentGateway`, `CheckoutService` (scadenza con margine, ADR-0012) | `Feature/Payments/StripeCheckoutTest`, `tests/Support/stripe_simulator.php` |
| Webhook Stripe (4.3) | `StripeWebhookController` (`POST /webhooks/stripe`), `App\Domain\Payments\Stripe\{StripeWebhookReceiver, StripeEventProcessor}`, `Jobs\ProcessStripeWebhookJob`, `StateMachines\WebhookEventStateMachine`, funzione `app_tenant_by_stripe_account(text)` (`2027_01_04_000100`) | `Feature/WebhookIdempotencyTest`, `Feature/Architecture/RlsCatalogTest` |
| Flussi A, B, R e rilascio con Stripe (4.4–4.6) | `CheckoutOutcomes`, `CapturePaymentJob`, `CancelAuthorizationJob`, `CheckoutService::releaseExpired`, promemoria `reservation.capture_failed_reminder` | `Feature/Payments/{StripeFlowsTest, StripeReleaseTest}`, `Feature/{ApprovalDeadlineTest, CaptureFailedTimeoutTest}` |
| Rimborsi (4.7) | `App\Domain\Payments\RefundService`, `RefundStateMachine`, `Jobs\ProcessRefundJob`, `Admin\RefundController` (`GET /admin/refunds`, `retry` e `mark-offline` con `reauth`) | `Feature/{RefundRetryTest, RefundEmailTimingTest}`, `Stripe/{CancelRefundTest, RefundFailureTest}` |
| Riconciliazione (4.8) | `App\Domain\Payments\PaymentReconciler`, `Jobs\{DispatchPaymentReconciliationJob, ReconcilePaymentsJob}` (`payments:reconcile` ogni 15 min, `payments:reconcile-capturing` ogni 5), `InventoryService::i15Violations` | `Feature/Payments/PaymentReconciliationTest` |
| Pagina ospite con Stripe (4.9) | `resources/js/guest/pages/{BookingPage, ReservationPage}.tsx` (checkout a pagina intera, polling) | `Feature/Payments/GuestReturnPageTest`, `E2E/m4-stripe-checkout.spec` |
| Pannello Pagamenti (4.10) | `resources/js/panel/pages/PaymentsPage.tsx`, `resources/js/panel/api/payments.ts`, `resources/js/panel/components/Reauth.tsx`, `Admin\DisputeController` (`GET /admin/disputes`) | `Feature/Payments/DisputeApiTest`, `Feature/Frontend/SpaRoutesTest` |
| Suite Stripe in modalità test (4.11) | `tests/Stripe/*`, `tests/Support/stripe_live.php`, `phpunit.stripe.xml`, `.github/workflows/stripe-nightly.yml` | `Stripe/*` (notturna, `STRIPE_LIVE_TESTS=1`) |
| `SafeHttpFetcher` | `App\Domain\Calendar\SafeHttpFetcher` (segnaposto fino a 5.1) | `Arch/ExternalHttpTest` |
| Struttura del codice (ADR-0006) | `app/Domain/<Area>`, `app/Http/Controllers/Api/V1/{Admin,Public}` | `Arch/DomainStructureTest` |
| SPA pannello e ospite | `resources/js/panel/main.tsx` su `/app`, `resources/js/guest/main.tsx` su `/p/{t}/{p}` | `Feature/Frontend/SpaRoutesTest` |

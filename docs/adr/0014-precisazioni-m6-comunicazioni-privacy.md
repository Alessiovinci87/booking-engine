# ADR-0014 — Precisazioni di M6: comunicazioni, privacy, metriche e allerte

- **Stato**: Proposta
- **Data**: 2026-11-07
- **Autori**: Claude Code (da verificare con Alessio)
- **Documenti di specifica toccati**: 01-SCOPE, 02-DOMAIN-MODEL, 07-API-CONTRACT, 08-SECURITY-OPERATIONS, 09-PRIVACY-GDPR, 10-TRACEABILITY

## Contesto

M6 parte senza gli account esterni (hosting, dominio, Resend, Sentry): si fanno le attività che non ne hanno bisogno (6.1, 6.2, 6.3, 6.5, 6.6, 6.7, 6.8). Le scelte di dettaglio che la specifica non fissa sono raccolte qui.

## Decisione

### Email (6.1)

1. **Mailer astratto** `App\Domain\Communications\TransactionalMailer`, in una nuova area di dominio `app/Domain/Communications` (si aggiunge alle aree di ADR-0006): i gestori dell'outbox costruiscono una `TransactionalEmail` (destinatario, lingua, testi tradotti, tipo, chiave di idempotenza, `reply_to`) e la consegnano al mailer; il trasporto dipende da `MAIL_MAILER`. Nessuna nuova libreria (ADR-0006).
2. **Driver `log`** (locale): `PreviewLogTransport` sostituisce il trasporto `log` di Laravel, che scriverebbe nel log l'intero messaggio con indirizzo e testi dell'ospite (08, 09). Scrive solo `mail.sent` con tipo, destinatario mascherato (`a***@dominio`) e Message-ID.
3. **Casella di anteprima** su `/dev/mail`: con `APP_ENV=local` il messaggio completo si salva come file JSON in `storage/app/mail-preview` (non nei log), al massimo 200 messaggi e 30 giorni (categoria G di 09). In ogni altro ambiente la rotta non esiste (staging, produzione) o risponde `404` (testing).
4. **Adattatore Resend** `ResendTransport`: API HTTP `POST /emails` con il client HTTP di Laravel, attivo con `MAIL_MAILER=resend` e `RESEND_API_KEY`; senza chiave non invia e l'outbox riprova. `Idempotency-Key` = `outbox:{id del messaggio}`; il tipo diventa un tag Resend; le intestazioni interne non arrivano al destinatario; gli errori riportano solo stato HTTP e nome dell'errore. Prova con il servizio vero in 6.4.
5. **`reply_to`** delle email all'ospite = email di contatto della struttura: l'ospite risponde al titolare, non alla piattaforma.
6. **Nuova email al gestore** `reservation.confirmed_manager` a ogni conferma online (T3, T12): 01 prevede email "a ospite e gestore" e il gestore non aveva avviso delle prenotazioni istantanee. Contiene il nome dell'ospite (il gestore è il titolare, come per `refund_reversed`).

### Metriche ed export CSV (6.2)

7. **Periodo delle metriche**: prenotazioni `CONFIRMED` con check-in tra `from` e `to` inclusi (predefinito: mese corrente; al massimo 366 giorni, altrimenti `400 VALIDATION_FAILED`). Tutte le prenotazioni del sistema sono dirette (pagina ospite, preventivo, manuale); le cancellate non contano.
8. **Commissioni OTA evitate**: per prenotazione, importo contrattuale × `ota_commission_bps` della sua struttura, arrotondato al centesimo; la risposta riporta la percentuale usata per ogni struttura (01).
9. **Commissioni di pagamento**: `payments.fee_cents` quando è registrato; altrimenti stima con la tariffa Stripe standard per carte SEE (1,5% + 0,25 €, `config/payments.php` `estimated_fee`) sui pagamenti online incassati, con `payment_fees_estimated = true` e una nota nel pannello. Oggi nessun flusso registra `fee_cents`: la lettura della commissione reale da Stripe (balance transaction) va fatta con le prove su Stripe vero.
10. **Sconti diretti**: `discount_cents` dei preventivi da cui nasce una prenotazione confermata del periodo.
11. **Export CSV** (`GET /admin/exports/reservations.csv`): prenotazioni con check-in nel periodo, esclusi i tentativi non conclusi (`AWAITING_PAYMENT`, `EXPIRED`, `PAYMENT_FAILED`, `REJECTED`); anagrafica dell'ospite; separatore `;`, BOM UTF-8, importi con la virgola; celle che iniziano con `=`, `+`, `-`, `@`, tab o a capo precedute da un apostrofo (formula injection). Generato e restituito nella stessa richiesta (nessun file salvato); registrato in `audit_log` come `export.reservations_csv` con periodo e numero di righe, senza dati personali. Senza ri-autenticazione: 08 la chiede per l'export completo del tenant.
12. **Pannello**: pagina `/app/metriche` con i tre valori, il dettaglio del calcolo, la nota di attendibilità ("non è un risparmio netto"), la percentuale per struttura e il pulsante del CSV.

### Privacy (6.3)

13. **Export dell'ospite** (`GET /admin/guests/{id}/export`): JSON scaricabile con dati personali, prenotazioni, pagamenti, rimborsi e copia del preventivo; elenca anche gli altri record `guests` con la stessa email nel tenant (un record per prenotazione, 02), così il gestore tratta tutte le prenotazioni della persona. Registrato in audit (`guest.exported`, solo conteggi). **Scostamento da 09**: il PDF non si genera sul server (servirebbe una libreria, ADR-0006); il pannello ha una versione stampabile (`/app/ospiti/{id}/dati`) da salvare in PDF dal browser. Da confermare alla verifica.
14. **Anonimizzazione** (`POST /admin/guests/{id}/erase`): copie trattate come da 09 — `guests` (nome, cognome, telefono `"[rimosso]"`, email `rimosso-{id}@anonimo.invalid`, note nulle, paese mantenuto, `anonymized_at`), `reservations.cancel_reason`, `reservation_changes.note`, `refunds.reason`, `inventory_conflicts.resolution_notes` dei conflitti sull'occupazione della prenotazione e `external_calendar_events.summary_redacted` degli eventi di quei conflitti (`"[rimosso]"`), preventivo d'origine (`guest_name` `"[rimosso]"`, `guest_email` nulla), payload dell'outbox che citano prenotazione, rimborsi o preventivo (svuotati subito, `payload_purged_at`). `inventory_occupancies.note` di una prenotazione è sempre nulla per vincolo (`occupancies_kind_consistent`: solo i blocchi hanno note), quindi non c'è nulla da togliere. Export: nessun file salvato (CSV e JSON si generano a richiesta), quindi nessun export attivo da cancellare. Idempotente; audit `guest.erased` con i soli conteggi.
15. **Blocco**: `409 GUEST_HAS_ACTIVE_RESERVATION` se una prenotazione dell'ospite occupa ancora le date (`AWAITING_*`, `CAPTURING`, `CAPTURE_FAILED`, `CONFIRMED`) e il check-out non è passato.
16. **Ri-autenticazione per l'anonimizzazione**: irreversibile, quindi rientra tra le azioni sensibili di 08 (password confermata negli ultimi 15 minuti).
17. **DPA**: `tenants.dpa_signed_at` e `dpa_version` si registrano dalla piattaforma con `php artisan tenant:dpa {slug} {versione} [--signed-at=]` (audit `tenant.dpa_recorded`); il pannello li vede in `GET /admin/me` e mostra un avviso finché mancano. La pubblicazione di una struttura richiede il DPA (`422 DPA_NOT_SIGNED`, dopo `PRIVACY_POLICY_MISSING` e prima di `PAYMENTS_NOT_ENABLED`): 09 requisito 1 "prima dell'attivazione".
18. **Informative**: l'informativa della struttura era già collegata nel checkout (`privacy_policy_url`, ADR-0011). Il piè di pagina della pagina ospite (anche preventivo ed embed) collega l'informativa della piattaforma e l'elenco dei sub-responsabili da `config/privacy.php` (`PLATFORM_PRIVACY_NOTICE_URL`, `PLATFORM_SUBPROCESSORS_URL`); senza configurazione il link non compare, e la checklist di rilascio lo richiede. I testi sono documenti legali da redigere con il professionista (09).

### Allerte operative (6.5)

19. **Canale provvisorio delle allerte "push"**: finché le notifiche push e Sentry non arrivano con 6.4, i segnali con canale push di 08 vanno nel log al loro livello e per email agli indirizzi della piattaforma (`ALERT_EMAILS`, `config/alerts.php`), tramite `OperationalAlerts`. Le email al gestore restano nell'outbox del tenant, come prima.
20. **Segnali coperti** (`AlertSignal`): coda in ritardo (messaggi dell'outbox pronti da più di 5 minuti, controllo ogni 5 minuti con `app_admin_ops`), webhook Stripe che falliscono (3 tentativi dello stesso evento), prenotazione in `CAPTURING` da oltre 1 ora, occupazione scaduta non rilasciata da oltre 30 minuti, incoerenza I15, rimborso riuscito annullato da Stripe, webhook con tenant non corrispondente, riepilogo giornaliero interno dei calendari `UNHEALTHY` (alle 7, ora italiana). Il rimborso fallito dopo 3 tentativi resta log ed email al gestore (08). Fuori da 6.5: errori applicativi, backup e ripristino, uptime (6.4); feed non letto dall'OTA (avviso nel pannello, già in M5).
21. **Limiti**: al più una email per segnale e chiave ogni 60 minuti (`ALERT_THROTTLE_MINUTES`); il log registra ogni occorrenza. L'email parte dopo il commit della transazione in corso; un errore di invio resta nel log (`alerts.email_failed`) e non blocca il chiamante. Contesto: solo identificativi e conteggi.

### Test E2E in locale (6.6, parziale)

22. **Prima tranche in locale**: 11 chiede gli E2E dei criteri di accettazione su staging con Stripe in modalità test e un server iCal finto in https. Senza staging (6.4) e con i risultati della Fase 0 ancora attesi (6.9, da fare prima di 6.6), questa tranche li esegue in locale sul database demo (`tests/E2E/m6-acceptance.spec.ts`) con il gateway finto (ADR-0011); 6.6 resta aperta finché la stessa serie non gira su staging. Coperti nel browser: AC2 (UID stabili nel feed, gateway finto), AC3, AC4, AC5, AC6, AC9 (rimborso avviato, gateway finto), AC13, AC17, I22 dal pannello; AC1 e AC8 erano già coperti da `m2-panel` e `m5-quote`.
23. **Da ripetere su staging**: tutti i passi di pagamento (checkout, autorizzazione, incasso, rimborso) con Stripe in modalità test e webhook firmati; l'import di un calendario da un URL https reale (in locale `SafeHttpFetcher` rifiuta, giustamente, gli indirizzi privati, e i calendari dimostrativi coprono solo la parte del pannello); AC7, AC14, AC15, AC19, AC10 e AC16, che richiedono il passare del tempo, l'iniezione di errori nel gateway o richieste a Stripe, restano coperti dai test Feature e `Stripe/*` finché non si scrivono gli scenari su staging; AC11 è coperto da `Feature/TenantIsolationApiTest` e dal catalogo RLS; AC12 è 6.4.
24. **Supporto E2E**: le email si verificano nella casella `/dev/mail` dopo un giro del dispatcher dell'outbox (`schedule:test --name=outbox:dispatch`, `tests/E2E/support/artisan.ts`); il setup globale azzera il contatore del limite di frequenza della prenotazione pubblica per 127.0.0.1 nella cache con prefisso E2E, perché tutti gli spec arrivano dallo stesso indirizzo.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Trasporto `resend` di Laravel con `resend/resend-php` | nuova dipendenza (ADR-0006); l'API HTTP basta e si simula con `Http::fake` |
| Trasporto `log` di Laravel così com'è | scrive nel log indirizzo e testo dell'ospite |
| PDF generato sul server (dompdf o simili) | nuova libreria (ADR-0006); la stampa del browser produce lo stesso PDF |
| Firma del DPA dal pannello del gestore | il DPA è un contratto firmato fuori piattaforma; la registrazione spetta alla piattaforma |
| Mailpit o MailHog per l'anteprima | un servizio in più nel `docker-compose` per una funzione che bastano 100 righe a coprire |

## Conseguenze

- Le email dipendono solo dalla configurazione: in 6.4 basta impostare `MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` sul dominio verificato.
- Un tipo di outbox in più (`reservation.confirmed_manager`).

## Impatto sui test

- Test aggiunti: `Feature/Communications/TransactionalMailTest`, `Feature/Metrics/MetricsTest`, `Feature/AnonymizationCoverageTest` (I22), `Feature/Public/PlatformPrivacyLinksTest`, casi DPA in `Feature/PropertyPublishTest`, `Feature/Communications/OperationalAlertsTest`, `E2E/m6-acceptance.spec` (in locale).
- Test modificati: `Feature/Reservations/ReservationTransitionsTest` (T3 genera anche `reservation.confirmed_manager`).
- Invarianti toccati: I22 (ora coperto da `Feature/AnonymizationCoverageTest`).

## Aggiornamento della specifica

- [ ] Documenti aggiornati con riferimento a questa ADR
- [ ] Versione della specifica incrementata

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

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Trasporto `resend` di Laravel con `resend/resend-php` | nuova dipendenza (ADR-0006); l'API HTTP basta e si simula con `Http::fake` |
| Trasporto `log` di Laravel così com'è | scrive nel log indirizzo e testo dell'ospite |
| Mailpit o MailHog per l'anteprima | un servizio in più nel `docker-compose` per una funzione che bastano 100 righe a coprire |

## Conseguenze

- Le email dipendono solo dalla configurazione: in 6.4 basta impostare `MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` sul dominio verificato.
- Un tipo di outbox in più (`reservation.confirmed_manager`).

## Impatto sui test

- Test aggiunti: `Feature/Communications/TransactionalMailTest`.
- Test modificati: `Feature/Reservations/ReservationTransitionsTest` (T3 genera anche `reservation.confirmed_manager`).
- Invarianti toccati: nessuno.

## Aggiornamento della specifica

- [ ] Documenti aggiornati con riferimento a questa ADR
- [ ] Versione della specifica incrementata

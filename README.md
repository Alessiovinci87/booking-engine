# Booking Engine — Specifica tecnica v1.1

Versione: **1.1** · Data: 06/11/2026 · Stato: **decisioni di prodotto chiuse; incoerenze della revisione tecnica v1.0 corrette** · Modifiche: [CHANGELOG-v1.1](docs/CHANGELOG-v1.1.md)

Questa cartella è la fonte di verità del comportamento del sistema. Il codice deriva da questi documenti, non il contrario. Ogni modifica a un comportamento concordato passa da una ADR in `docs/adr/`.

## Indice

| # | Documento | Contenuto |
|---|---|---|
| 01 | [SCOPE](docs/01-SCOPE.md) | Perimetro MVP 1.0, esclusioni, criteri di accettazione |
| 02 | [DOMAIN-MODEL](docs/02-DOMAIN-MODEL.md) | Entità, relazioni, vincoli, invarianti |
| 03 | [BOOKING-STATE-MACHINE](docs/03-BOOKING-STATE-MACHINE.md) | Stati di prenotazione, pagamento, rimborso, preventivo, conflitto; transizioni e timeout |
| 04 | [PRICING-SPEC](docs/04-PRICING-SPEC.md) | Formule, stagionalità, supplementi, arrotondamenti |
| 05 | [PAYMENTS-SPEC](docs/05-PAYMENTS-SPEC.md) | Stripe Connect, pre-autorizzazione, webhook, rimborsi |
| 06 | [CALENDAR-SYNC](docs/06-CALENDAR-SYNC.md) | iCal import/export, conflitti, modalità degradata |
| 07 | [API-CONTRACT](docs/07-API-CONTRACT.md) | Endpoint REST v1, autorizzazioni, errori, idempotenza |
| 08 | [SECURITY-OPERATIONS](docs/08-SECURITY-OPERATIONS.md) | Multi-tenancy, RLS, autenticazione, backup, incidenti |
| 09 | [PRIVACY-GDPR](docs/09-PRIVACY-GDPR.md) | Ruoli, DPA, retention, diritti, data breach |
| 10 | [TRACEABILITY](docs/10-TRACEABILITY.md) | Matrice requisito → servizio → tabella → endpoint → test |
| 11 | [MILESTONES](docs/11-MILESTONES.md) | Piano di sviluppo vincolante: milestone M0–M7, attività, criteri di uscita |
| — | [CHANGELOG-v1.1](docs/CHANGELOG-v1.1.md) | Le 10 correzioni della revisione tecnica, una per una |
| — | [ADR-0001](docs/adr/0001-decisioni-v1.md) | Decisioni fondative v1.0 e fatti verificati |
| — | [ADR-0002](docs/adr/0002-inventario-unico.md) | Occupazione unica come garanzia anti-overbooking |
| — | [ADR-0003](docs/adr/0003-feed-ical-per-destinazione.md) | Feed iCal per destinazione, echi, ritiro dei feed |
| — | [ADR-0004](docs/adr/0004-ciclo-di-vita-pagamenti.md) | Scadenze del checkout, recupero capture, rimborsi, prenotazioni manuali |
| — | [ADR-0005](docs/adr/0005-fk-composite-tenant.md) | Chiavi esterne composite per l'integrità tra tenant |
| — | [ADR-0006](docs/adr/0006-scelte-di-implementazione.md) | Scelte di implementazione: ambiente Windows, librerie, struttura del codice |
| — | [ADR-0007](docs/adr/0007-sviluppo-anticipato.md) | Sviluppo anticipato: lo sviluppo non attende la decisione della Fase 0, nuove scadenze M1–M7 |
| — | [ADR template](docs/adr/0000-template.md) | Modello per le decisioni successive |
| — | [CLAUDE.md](CLAUDE.md) | Istruzioni per Claude Code |

## Stack definitivo

| Elemento | Scelta |
|---|---|
| Backend | Laravel 13.x, PHP 8.4 |
| Database | PostgreSQL (con `btree_gist`), Row Level Security |
| Code | Redis + Laravel Horizon |
| Frontend | React + TypeScript (pagina ospite + pannello gestore) |
| API | REST JSON versionata `/api/v1` |
| Autenticazione | Laravel Sanctum, MFA per gli amministratori |
| Pagamenti | Stripe Connect, direct charges, Stripe Checkout ospitato |
| Email | Provider transazionale via API (Resend) |
| File | Storage S3-compatibile privato, URL firmati |
| Hosting | Unione Europea, staging e produzione separati |
| Test | Pest/PHPUnit + Playwright (end-to-end) |
| CI | GitHub Actions |

Prima del bootstrap: verificare le ultime patch di Laravel 13 e la compatibilità delle dipendenze Composer.

## Convenzioni trasversali

- **Date di soggiorno**: tipo `DATE`, intervallo semiaperto `[check_in, check_out)`. Il giorno di check-out non è una notte occupata.
- **Timestamp**: `TIMESTAMPTZ` in UTC; visualizzazione nel fuso della struttura (`Europe/Rome` di default).
- **Denaro**: interi in centesimi (`BIGINT`) + codice valuta esplicito. Solo `EUR` nella 1.0.
- **Identificatori**: UUID v7 per le entità; mai ID sequenziali esposti all'esterno.
- **Lingue**: chiavi di traduzione fin dal primo commit; ospite IT/EN, pannello solo IT.
- **Termini**: *pubblicato* (il nostro ICS contiene l'occupazione) ≠ *recepito* (l'OTA l'ha applicata). Non usare mai "sincronizzato" per indicare la sola pubblicazione.

## Dipendenze esterne da validare prima della vendita

Non bloccano la progettazione di database, API e backend, ma bloccano la vendita delle funzionalità corrispondenti.

- [ ] Configurazione Stripe Connect disponibile (account connessi con dashboard completa, direct charges)
- [ ] Test end-to-end di manual capture e 3D Secure in modalità test
- [ ] Contratto commerciale Channex (durata, white-label, costi effettivi)
- [ ] Condizioni API/partner Chekin
- [ ] Verifica legale di DPA, informative e condizioni di prenotazione

## Ordine di derivazione del codice

1. Migration PostgreSQL (da 02), con FK composite e vincolo GiST su `inventory_occupancies`
2. Policy RLS (da 08)
3. Macchine a stati (da 03)
4. Contratti REST (da 07)
5. Test di concorrenza, Stripe, sincronizzazione, isolamento multi-tenant (invarianti I1–I22 di 02, matrice di 10)

# ADR-0001 — Decisioni fondative della specifica v1.0

- **Stato**: Accettata; punti 6 e i flussi di pagamento precisati da ADR-0002, ADR-0004; export iCal sostituito da ADR-0003
- **Data**: 2026-11-05
- **Autori**: Alessio, con revisione incrociata Claude / ChatGPT
- **Documenti toccati**: tutti (01–09)

## Contesto

Specifica consolidata in tre giri di revisione (programma iniziale → analisi → v0.9 → v1.0). Fatti verificati su fonti ufficiali al 05/11/2026:

- Booking.com ha sospeso le integrazioni con nuovi connectivity provider fino a nuova comunicazione (connect.booking.com).
- Channex white-label: 130 $/mese + 0,50 $ per unità affitto breve con canale attivo; produzione soggetta a certificazione Channex e autorizzazione OTA.
- Airbnb aggiorna i calendari importati circa ogni 3 ore.
- Commissione UE, 28/09/2026: le strutture nel SEE possono fissare prezzi e condizioni liberamente anche sul canale diretto (DMA).
- Laravel 13 rilasciato il 17/03/2026; Laravel 12 fuori dai bug fix dal 13/08/2026.
- Stripe: autorizzazione carta online valida di norma circa 7 giorni (`capture_before` da leggere sulla Charge).
- Chekin: pagina di supporto indica un minimo di 500 €/mese per l'accesso API, condizioni partner da confermare.

## Decisioni

1. MVP focalizzato su prenotazione diretta: pagina ospitata, iCal, Stripe, preventivi. Niente channel manager API, niente adempimenti.
2. Unità con calendari esterni sempre in modalità `REQUEST`; `INSTANT` solo senza calendari esterni.
3. `REQUEST` usa pre-autorizzazione Stripe (`capture_method: manual`) solo sull'importo della caparra; approvazione entro 12 h; capture all'approvazione.
4. Stripe Connect con dashboard completa e direct charges, commissione applicativa 0%.
5. Multi-tenancy con policy Laravel **e** PostgreSQL RLS forzata, contesto con `SET LOCAL`.
6. Vincoli di esclusione PostgreSQL contro l'overbooking interno; disponibilità effettiva su cinque fonti.
7. `InventoryConflict` entità separata; la prenotazione resta `CONFIRMED`.
8. Channex come provider preferito dietro `ChannelManagerProvider`, sviluppato dopo l'MVP e prima degli adempimenti; attivazione commerciale con almeno 8 clienti Pro o canone che copre il fisso.
9. Adempimenti dietro `ComplianceProvider`; Chekin da valutare economicamente; nessuno sviluppo proprietario prima della validazione.
10. Pagina ospite, email e preventivi IT/EN; pannello IT; nessuna traduzione automatica dei contenuti del gestore.
11. Stack: Laravel 13, PHP 8.4, PostgreSQL, Redis/Horizon, React + TypeScript.
12. Listino sperimentale per struttura (29/49/79–99 €), da validare in fase 0.

## Conseguenze

Il sistema non promette mai sincronizzazione istantanea né rischio zero di doppia prenotazione. La vendita delle funzionalità dipende dalle validazioni esterne elencate nel README.

## Impatto sui test

Gli invarianti I1–I14 (02) e i criteri di accettazione (01) sono la base della suite.

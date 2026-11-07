# ADR-0017 — Milestone M8 "Qualità del frontend"

- **Stato**: Proposta
- **Data**: 2026-11-07
- **Autori**: Claude Code (stesura) su richiesta di Alessio
- **Documenti di specifica toccati**: 11-MILESTONES (nuova sezione M8, versione 1.4), ADR-0006 (strumenti di test del frontend), 10-TRACEABILITY

## Contesto

- Le milestone M1–M7 hanno costruito le funzioni della 1.0. L'interfaccia c'è per ogni funzione, ma è nata pagina per pagina: colori, pulsanti e messaggi non sono uniformi.
- Non ci sono controlli automatici di accessibilità né misure di velocità sul telefono.
- Prima delle demo ai gestori e del pilota serve una pagina ospite che ispiri fiducia su un telefono e un pannello comprensibile per un gestore non tecnico.
- La maggior parte degli ospiti arriva dal telefono, spesso da un link in chat o da un'email.
- Dal 28 giugno 2025 lo European Accessibility Act chiede l'accessibilità ai servizi di commercio elettronico verso i consumatori. Le microimprese sono esentate. La sua applicazione ai nostri clienti va verificata, ma WCAG 2.2 AA è il riferimento comune.

## Decisione

Si aggiunge la milestone **M8 "Qualità del frontend"**, da sviluppare dopo M7 (senza 7.3) sul branch `m8-qualita-frontend`.

**Perimetro: solo qualità.**
- Nessuna funzione nuova e nessun cambio di comportamento del backend: API, codici di errore, stati e dati restano quelli di 01–09.
- Il lavoro riguarda pagina ospite, vista incorporata, pagina del preventivo e pannello.

Attività:
- **8.1 Fondamenta grafiche**: token di design nel tema Tailwind e componenti interni coerenti.
- **8.2 Accessibilità WCAG 2.2 AA**, verificata con `@axe-core/playwright` su tutte le pagine.
- **8.3 Telefono** (360–430 px), con screenshot E2E su tre dispositivi.
- **8.4 Velocità**: budget della pagina ospite, misurato con Lighthouse in locale.
- **8.5 Stati e messaggi**: caricamento, vuoto, errore e conferme.
- **8.6 Testi** e glossario.
- **8.7 Fiducia nella pagina ospite**: presentazione di dati già esistenti.

Nuovi strumenti, solo di sviluppo (aggiunti ad ADR-0006):
- `@axe-core/playwright`: controllo automatico di accessibilità negli E2E;
- `lighthouse`: misura locale di prestazioni e accessibilità della pagina ospite, eseguita con il Chromium di Playwright.

Componenti interni, nessuna libreria di componenti:
- sono scritti nel progetto con Tailwind;
- ogni nuova libreria a runtime richiede una nuova ADR.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Libreria di componenti (es. una UI kit React) | Peso del JavaScript iniziale (budget 200 KB) e un'altra dipendenza da mantenere; servono pochi componenti. |
| Rinviare la qualità dopo il pilota | Le demo ai gestori e il pilota sono il momento in cui la prima impressione conta di più. |
| Controlli di accessibilità solo manuali | Non ripetibili: una regressione passerebbe inosservata. |
| Lighthouse in CI | Misure instabili sulle macchine condivise di CI; per ora in locale, con valori riportati nel rapporto. |

## Conseguenze

- Nessuna migration e nessuna modifica alle API.
- Le pagine cambiano aspetto. Gli E2E esistenti devono restare verdi: si aggiornano solo i selettori legati alla presentazione, mai il comportamento verificato.
- Due dipendenze di sviluppo in più (`@axe-core/playwright`, `lighthouse`).
- Rischio: il lavoro di frontend può allargarsi senza fine. Il perimetro è chiuso dalle sette attività e dal divieto di nuove funzioni.

## Impatto sui test

- Test da aggiungere:
  - E2E di accessibilità con axe su tutte le pagine (zero violazioni serie o critiche);
  - E2E sul telefono con screenshot (iPhone SE, iPhone 15, Pixel 7);
  - controllo del budget del JavaScript iniziale della pagina ospite.
- Test da modificare: E2E esistenti solo nei selettori, se necessario.
- Invarianti toccati: nessuno (nessun cambio del backend).

## Aggiornamento della specifica

- [x] 11-MILESTONES: sezione M8, versione 1.4
- [x] ADR-0006: `@axe-core/playwright` e Lighthouse
- [ ] Versione della specifica incrementata alla verifica di M8

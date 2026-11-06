# ADR-0003 — Feed iCal per destinazione, gestione degli echi, ritiro dei feed

- **Stato**: Accettata
- **Data**: 2026-11-06
- **Documenti toccati**: 02, 06, 07, 09, 01
- **Origine**: revisione tecnica v1.0, punti 1, 5 e 8 (CHANGELOG C1, C5, C8)

## Contesto

Il feed unico della 1.0 non sapeva a quale OTA era destinato e non riesportava gli eventi importati: una prenotazione Booking non bloccava Airbnb attraverso il nostro sistema. Inoltre all'uscita del cliente il feed veniva disattivato subito, con il rischio che un'OTA mostrasse libere date già vendute. Non abbiamo ancora verificato come Booking e Airbnb trattano i calendari importati.

## Decisione

1. Topologia a stella: il nostro sistema è il centro; ogni OTA importa solo il proprio feed dedicato; i collegamenti iCal diretti tra OTA vanno rimossi in fase di attivazione.
2. Un feed per unità e destinazione, accoppiato al calendario importato dallo stesso canale. Il feed per X contiene tutte le occupazioni tranne quelle importate da X e gli echi.
3. Echi: confermati (UID nostro) e sospetti (intervallo identico a un evento che esportiamo verso quella OTA). Bloccano le date, non vengono mai riesportati.
4. Feed `ACTIVE` → `FROZEN` → `RETIRED`; mai un calendario vuoto; ritiro solo dopo conferma del gestore o alle condizioni previste.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Feed unico con tutti gli eventi | ogni OTA riceverebbe le proprie prenotazioni come blocchi esterni: echi garantiti e conflitti falsi |
| Affidarsi alla sincronizzazione diretta tra OTA | comportamento non verificato e non sotto il nostro controllo |
| Spegnere il feed restituendo un calendario vuoto | l'OTA libererebbe date vendute |

## Conseguenze

- Il gestore collega più URL per unità: serve una checklist chiara nel pannello.
- Le echi sospette possono generare conflitti da chiudere a mano finché la fase 0 non chiarisce il comportamento reale.

## Impatto sui test

- Nuovi: matrice dei flussi, ciclo eco, UID stabile, ciclo di vita dei feed.
- Invarianti: I18, I19 nuove.

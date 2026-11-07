# Uscita di un cliente dalla piattaforma

> Procedura di piattaforma (7.1, ADR-0018). Da rivedere con Giuseppe insieme alla [procedura di attivazione](attivazione-pilota.md).

L'uscita serve a lasciare la piattaforma **senza perdere prenotazioni**. Il rischio principale è questo: se un calendario esportato verso un'OTA sparisce o diventa vuoto, l'OTA può mostrare libere date già vendute. Per questo i calendari non si spengono mai da soli finché un'OTA li legge.

## 1. Avvio

L'avvio lo può fare il gestore da **Account → Uscita dalla piattaforma** (chiede la password), oppure la piattaforma su richiesta scritta del cliente:

```
php artisan tenant:offboarding <slug> start
```

Effetti:

- la pagina di prenotazione e i link dei preventivi smettono di funzionare;
- il pannello diventa in sola lettura. Restano possibili cancellazioni, rifiuti delle richieste, rimborsi, anonimizzazione degli ospiti, export e conferme dei calendari;
- ogni calendario esportato passa a **congelato**: continua a mostrare le date già occupate, senza nuove vendite.

## 2. Export completo

Il gestore lo richiede dalla stessa pagina; la piattaforma può farlo con:

```
php artisan tenant:offboarding <slug> export
```

- L'archivio ZIP contiene:
  - un file CSV per ogni tipo di dato;
  - `export.json` con gli stessi dati;
  - `prenotazioni_future.csv` con le prenotazioni da riportare nel nuovo sistema.
- I proprietari ricevono un'email quando è pronto. Il download si fa solo dal pannello, con la password.
- È scaricabile per **30 giorni**, poi il file viene cancellato. Se serve, se ne richiede un altro.

## 3. Scollegamento dei calendari dalle OTA

1. Il gestore toglie ogni calendario dalle impostazioni della OTA: Booking.com, Airbnb, ecc.
2. Nella pagina dell'uscita conferma "Ho tolto il calendario" per ogni riga. La pagina mostra l'ultima lettura di ciascuna OTA: se è recente, la OTA sta ancora leggendo il calendario.
3. Dopo la conferma il link del calendario risponde "non più disponibile" (410).

Ogni notte la piattaforma controlla i calendari ancora congelati:

- **meno di 30 giorni dall'avvio**: nessun ritiro automatico;
- **dopo 30 giorni**:
  - un calendario che nessuna OTA legge da almeno 7 giorni viene ritirato;
  - un calendario ancora letto **resta congelato**. I proprietari ricevono un'email, una sola volta per calendario, e bisogna contattare il gestore perché lo tolga dalla OTA.

Quando nessun calendario è più servito, anche i calendari importati si disattivano.

Stato in qualunque momento:

```
php artisan tenant:offboarding <slug> status
```

## 4. Altri collegamenti

- **Stripe**: l'account Stripe resta della struttura. Dalla dashboard di Stripe Connect si revoca solo l'accesso della piattaforma all'account collegato, dopo che tutti i rimborsi sono chiusi.
- **Domini della vista incorporata**: `php artisan tenant:embed-origins <slug> --remove=…` per ogni dominio, se servono ancora.

## 5. Cancellazione dei dati (DPA)

Si fa solo quando:

- tutti i calendari sono ritirati (il comando lo verifica sempre), **e**
- sono passati almeno 30 giorni da un export completato, **oppure** il cliente ha chiesto per iscritto di cancellare prima.

```
php artisan tenant:offboarding <slug> purge
php artisan tenant:offboarding <slug> purge --instruction="Richiesta scritta del cliente del <data>"
```

- L'istruzione è un riassunto di 10–200 caratteri: **niente email, telefoni o altri dati personali**. Finisce nell'audit.
- Il comando chiede conferma e non si può annullare.
- **Cosa si cancella**:
  - tutti i dati della struttura: prenotazioni, ospiti, pagamenti, preventivi, tariffe, calendari, foto ed export;
  - gli utenti che appartenevano solo a questo cliente.
- **Cosa resta**:
  - la riga del cliente (nome, piano, DPA) con stato **chiuso**;
  - il registro delle operazioni, che non contiene dati personali.
- I backup scadono da soli con la rotazione (PITR di Railway e dump cifrato, ADR-0015).

## 6. Chiusura

Si invia al cliente una conferma scritta della cancellazione, con la data.

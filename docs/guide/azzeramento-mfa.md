# Azzeramento della verifica in due passaggi (MFA) di un gestore

> Procedura di piattaforma (6.10). Da rivedere con Giuseppe insieme alla [procedura di attivazione](attivazione-pilota.md).

Si usa **solo** quando il gestore ha perso sia l'app di autenticazione sia tutti i codici di recupero. Se ha ancora un codice di recupero, accede con quello e riconfigura la MFA da solo: niente intervento.

## 1. Verifica dell'identità (obbligatoria)

1. La richiesta arriva per email o telefono. **Non si agisce sulla sola email**: potrebbe essere un tentativo di furto dell'account.
2. Si richiama il gestore **al numero registrato nel documento del pilota** (raccolto all'attivazione), non a un numero indicato nella richiesta.
3. Si verificano almeno due elementi che solo il gestore conosce: per esempio il nome di una struttura e il codice di una prenotazione recente.
4. Se qualcosa non torna, ci si ferma e se ne parla con Alessio.

## 2. Esecuzione (sul server di produzione)

```
php artisan user:reset-mfa <email-del-gestore> --reason="Telefono perso, identità verificata per telefono il <data>"
```

- Il comando ricorda di verificare l'identità per telefono e chiede conferma.
- La motivazione è obbligatoria (10–200 caratteri) e finisce nell'audit del tenant, visibile al gestore: **niente email, numeri di telefono o altri dati personali**. Il comando rifiuta motivazioni con email o numeri di telefono.
- Effetti:
  - segreto TOTP e codici di recupero cancellati;
  - tutte le sessioni aperte del gestore si chiudono alla richiesta successiva;
  - al prossimo accesso, dopo la password, il pannello chiede di configurare di nuovo la MFA;
  - audit `user.mfa_reset` con l'ID dell'utente e la motivazione.

## 3. Dopo

1. Si avvisa il gestore per telefono che può accedere e configurare la MFA.
2. Gli si ricorda di **salvare i nuovi codici di recupero**.
3. Se c'è il sospetto che qualcuno abbia avuto accesso al telefono o alla password, si controlla l'audit recente. Il gestore cambia la password dal pannello ("Account" → "Cambia password") oppure con "Password dimenticata?" (6.11, ADR-0016): in entrambi i casi le altre sessioni si chiudono e arriva un'email di avviso. Nei casi gravi si segue la gestione degli incidenti di 08 (P1).

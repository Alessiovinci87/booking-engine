<?php

// Email delle transizioni della prenotazione (03). Invio vero da M6; in locale mailer "log".
return [
    'greeting' => 'Gentile ospite,',
    'signature' => 'Lo staff di :property',
    'confirmed' => [
        'subject' => 'Prenotazione confermata :code',
        'lines' => ['La prenotazione :code per :unit dal :check_in al :check_out è confermata.', 'Totale del soggiorno: :amount.'],
    ],
    'ops_alert' => [
        'subject' => '[Allerta] :signal',
        'intro' => 'Allerta operativa: :signal (livello :level). Dettagli tecnici, solo identificativi:',
        'signature' => 'Monitoraggio della piattaforma',
    ],
    'confirmed_manager' => [
        'subject' => 'Nuova prenotazione confermata :code',
        'lines' => ['La prenotazione :code di :guest per :unit dal :check_in al :check_out è confermata e pagata online.', 'Totale del soggiorno: :amount. Le date sono bloccate su tutti i canali al prossimo aggiornamento dei calendari.'],
    ],
    'awaiting_approval' => [
        'subject' => 'Richiesta ricevuta :code',
        'lines' => ['Abbiamo ricevuto la richiesta :code per :unit dal :check_in al :check_out.', 'L’importo è solo autorizzato: verrà addebitato se la struttura conferma.'],
    ],
    'approval_requested' => [
        'subject' => 'Nuova richiesta da approvare :code',
        'lines' => ['Nuova richiesta :code per :unit dal :check_in al :check_out.', 'Prima di approvare, verifica il calendario di Booking e Airbnb.'],
    ],
    'expired' => [
        'subject' => 'Prenotazione non completata :code',
        'lines' => ['Il pagamento della prenotazione :code non è stato completato in tempo e le date sono state liberate.'],
    ],
    'payment_failed' => [
        'subject' => 'Pagamento non riuscito :code',
        'lines' => ['Il pagamento della prenotazione :code non è riuscito e le date sono state liberate.'],
    ],
    'late_payment' => [
        'subject' => 'Pagamento arrivato in ritardo :code',
        'lines' => ['Il pagamento della prenotazione :code è arrivato dopo la scadenza e le date non erano più riservate.', 'Ci scusiamo: l’importo ti verrà restituito automaticamente.'],
    ],
    'rejected' => [
        'subject' => 'Richiesta non accettata :code',
        'lines' => ['La struttura non ha potuto accettare la richiesta :code. Nessun importo è stato addebitato.'],
    ],
    'approval_expired' => [
        'subject' => 'Richiesta scaduta :code',
        'lines' => ['La richiesta :code è scaduta senza risposta della struttura. Nessun importo è stato addebitato.'],
    ],
    'approval_expired_manager' => [
        'subject' => 'Richiesta scaduta senza risposta :code',
        'lines' => ['La richiesta :code per :unit dal :check_in al :check_out è scaduta e le date sono state liberate.'],
    ],
    'capture_failed' => [
        'subject' => 'URGENTE: incasso non riuscito :code',
        'lines' => ['L’incasso della prenotazione :code non è riuscito. Le date restano occupate per 24 ore.', 'Invia un link di pagamento all’ospite oppure rilascia le date.'],
    ],
    'recovery_link' => [
        'subject' => 'Completa il pagamento :code',
        'lines' => ['Il pagamento della prenotazione :code non è andato a buon fine. Puoi completarlo dalla pagina della prenotazione.'],
    ],
    'dates_released' => [
        'subject' => 'Prenotazione annullata :code',
        'lines' => ['Il pagamento della prenotazione :code non è stato completato e le date sono state liberate.'],
    ],
    'cancelled' => [
        'subject' => 'Prenotazione cancellata :code',
        'lines' => ['La prenotazione :code è stata cancellata.', 'Rimborso avviato: :amount. Ti scriveremo quando sarà completato.'],
    ],
    'refund_completed' => [
        'subject' => 'Rimborso completato :code',
        'lines' => ['Il rimborso di :amount per la prenotazione :code è stato completato.'],
    ],
    'dispute_opened' => [
        'subject' => 'URGENTE: contestazione di un pagamento :code',
        'lines' => ['L’ospite ha contestato con la banca il pagamento della prenotazione :code.', 'Rispondi alla contestazione dalla tua dashboard Stripe entro la scadenza indicata da Stripe. La prenotazione non cambia stato.'],
    ],
    'refund_failed' => [
        'subject' => 'Rimborso non riuscito :code',
        'lines' => ['Il rimborso di :amount per la prenotazione :code non è riuscito dopo tre tentativi automatici.', 'Dal pannello puoi riprovare oppure segnarlo come rimborsato fuori piattaforma (per esempio con un bonifico).'],
    ],
    'refund_reversed' => [
        'subject' => 'Urgente: rimborso non andato a buon fine :code',
        'lines' => ['Il rimborso a :guest di :amount per la prenotazione :code non è andato a buon fine: Stripe lo ha segnalato dopo averlo dato per riuscito. Contatta l’ospite.', 'L’ospite non ha ricevuto nessun avviso automatico. Dal pannello puoi riprovare il rimborso oppure segnarlo come rimborsato fuori piattaforma (per esempio con un bonifico).'],
    ],
    'capture_failed_reminder' => [
        'subject' => 'Promemoria: incasso non riuscito :code',
        'lines' => ['L’incasso della prenotazione :code non è ancora risolto. Tra 12 ore le date verranno liberate.', 'Invia un link di pagamento all’ospite oppure rilascia le date.'],
    ],
    'calendar_conflict_opened' => [
        'subject' => 'Conflitto di calendario: :unit dal :check_in al :check_out',
        'lines' => ['Le date dal :check_in al :check_out di :unit (:property) risultano occupate da due fonti diverse.', 'Le date restano bloccate e nessuna prenotazione viene cancellata in automatico. Controlla nel pannello quale prenotazione tenere.'],
    ],
    'calendar_conflict_auto_resolved' => [
        'subject' => 'Conflitto di calendario risolto: :unit dal :check_in al :check_out',
        'lines' => ['L’evento esterno che causava il conflitto su :unit (:property) dal :check_in al :check_out è stato rimosso dal calendario dell’OTA.', 'Il conflitto è stato chiuso automaticamente. Le altre prenotazioni sulle stesse date restano invariate.'],
    ],
    'calendar_sync_unhealthy' => [
        'subject' => 'Calendario :source non sincronizzato: :unit',
        'lines' => ['Da oltre 3 ore non riusciamo a leggere il calendario :source di :unit (:property).', 'Le date già importate restano bloccate, ma eventuali nuove prenotazioni dell’OTA potrebbero non essere visibili. Controlla il collegamento nel pannello.'],
    ],
    'quote_sent' => [
        'subject' => 'Il tuo preventivo per :property',
        'lines' => ['Ecco il preventivo per :unit dal :check_in al :check_out: totale :amount.', 'Il preventivo è valido fino al :expires_at (UTC). Puoi prenotare dal link qui sotto.'],
    ],
    // Account del gestore (6.11): testi del pannello, solo in italiano.
    'account' => [
        'greeting' => 'Buongiorno,',
        'signature' => 'Il team di :app',
    ],
    'account_password_reset' => [
        'subject' => 'Reimposta la password del pannello',
        'lines' => ['Abbiamo ricevuto una richiesta di reimpostazione della password del tuo account.', 'Il link qui sotto vale 60 minuti e si può usare una sola volta. Dopo la nuova password ti verrà chiesto comunque il codice della verifica in due passaggi.', 'Se non hai chiesto tu la reimpostazione, ignora questa email: la password attuale resta valida.'],
    ],
    'account_password_changed' => [
        'subject' => 'La tua password è stata cambiata',
        'lines' => ['La password del tuo account del pannello è stata cambiata il :changed_at (UTC). Tutte le altre sessioni aperte sono state chiuse.', 'Se non hai cambiato tu la password, reimpostala subito dal link qui sotto e contatta l’assistenza.'],
    ],
    'tenant_export_ready' => [
        'subject' => 'L’export dei tuoi dati è pronto',
        'lines' => ['L’export completo dei dati della tua struttura è pronto: lo trovi nella pagina "Uscita dalla piattaforma" del pannello, dove ti chiederemo la password.', 'Si può scaricare fino al :expires_at; dopo questa data il file viene cancellato.'],
    ],
    'offboarding_feed_still_read' => [
        'subject' => 'Il calendario di :unit è ancora letto da :destination',
        'lines' => [':destination ha letto il calendario di :unit l’ultima volta il :last_fetched_at: il collegamento è ancora attivo, quindi non lo disattiviamo per non lasciare date libere per errore.', 'Togli il calendario dalle impostazioni di :destination e conferma nella pagina "Uscita dalla piattaforma" del pannello.'],
    ],
];

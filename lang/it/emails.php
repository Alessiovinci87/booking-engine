<?php

// Email delle transizioni della prenotazione (03). Invio vero da M6; in locale mailer "log".
return [
    'greeting' => 'Gentile ospite,',
    'signature' => 'Lo staff di :property',
    'confirmed' => [
        'subject' => 'Prenotazione confermata :code',
        'lines' => ['La prenotazione :code per :unit dal :check_in al :check_out è confermata.', 'Totale del soggiorno: :amount.'],
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
];

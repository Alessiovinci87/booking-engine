<?php

/*
 * Messaggi di validazione in italiano per le regole usate dall'applicazione (pannello e comandi
 * interni). Le regole nuove aggiungono qui la loro chiave.
 */
return [
    'required' => 'Il campo :attribute è obbligatorio.',
    'required_with' => 'Il campo :attribute è obbligatorio.',
    'required_without' => 'Il campo :attribute è obbligatorio se manca :values.',
    'string' => 'Il campo :attribute deve essere un testo.',
    'array' => 'Il campo :attribute non ha il formato previsto.',
    'boolean' => 'Il campo :attribute deve essere vero o falso.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'url' => 'Il campo :attribute deve essere un indirizzo https valido.',
    'uuid' => 'Il campo :attribute non è valido.',
    'exists' => 'Il valore di :attribute non è valido.',
    'unique' => 'Il valore di :attribute è già in uso.',
    'regex' => 'Il formato di :attribute non è valido.',
    'enum' => 'Il valore di :attribute non è valido.',
    'in' => 'Il valore di :attribute non è valido.',
    'timezone' => 'Il campo :attribute deve essere un fuso orario valido.',
    'date' => 'Il campo :attribute deve essere una data valida.',
    'date_format' => 'Il campo :attribute deve avere il formato :format.',
    'after' => 'Il campo :attribute deve essere successivo a :date.',
    'after_or_equal' => 'Il campo :attribute deve essere uguale o successivo a :date.',
    'before' => 'Il campo :attribute deve essere precedente a :date.',
    'gte' => [
        'numeric' => 'Il campo :attribute deve essere almeno :value.',
    ],
    'between' => [
        'numeric' => 'Il campo :attribute deve essere tra :min e :max.',
        'string' => 'Il campo :attribute deve avere tra :min e :max caratteri.',
    ],
    'size' => [
        'string' => 'Il campo :attribute deve avere :size caratteri.',
    ],
    'max' => [
        'string' => 'Il campo :attribute non può superare :max caratteri.',
        'numeric' => 'Il campo :attribute non può superare :max.',
        'file' => 'Il file non può superare :max kilobyte.',
    ],
    'min' => [
        'string' => 'Il campo :attribute deve avere almeno :min caratteri.',
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
    ],
    'file' => 'Il campo :attribute deve essere un file.',
    'image' => 'Il file deve essere un\'immagine.',
    'mimes' => 'Il file deve essere di tipo: :values.',
    'mimetypes' => 'Il file deve essere di tipo: :values.',
    'list' => 'Il campo :attribute deve essere un elenco.',
    'dimensions' => 'L\'immagine è troppo grande.',
    'uploaded' => 'Il caricamento del file non è riuscito.',
    'distinct' => 'Il campo :attribute contiene valori ripetuti.',
    'password' => [
        'uncompromised' => 'Questa password è comparsa in una violazione di dati nota: scegline un\'altra.',
    ],

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'name' => 'nome',
        'code' => 'codice',
        'recovery_code' => 'codice di recupero',
        'slug' => 'indirizzo breve',
        'base_occupancy' => 'capienza base',
        'max_occupancy' => 'capienza massima',
        'min_nights' => 'minimo notti',
        'cleaning_fee_cents' => 'pulizie',
        'extra_guest_fee_cents' => 'supplemento ospite extra',
        'base_nightly_cents' => 'prezzo base',
        'nightly_cents' => 'prezzo per notte',
        'first_night' => 'prima notte',
        'last_night' => 'ultima notte',
        'priority' => 'priorità',
        'check_in' => 'arrivo',
        'check_out' => 'partenza',
        'adults' => 'adulti',
        'children' => 'bambini',
    ],
];

<?php

namespace App\Domain\Calendar;

/**
 * Unico client HTTP per URL forniti dagli utenti (06, 08; CLAUDE.md regola 10).
 *
 * Segnaposto di M0: allowlist di schema, risoluzione DNS controllata, blocco di reti private e
 * riservate, ricontrollo dopo redirect, limiti di dimensione e tempo arrivano con l'attività 5.1.
 * Il controllo tests/Arch/ExternalHttpTest impedisce già oggi chiamate HTTP fuori da questa classe.
 */
final class SafeHttpFetcher {}

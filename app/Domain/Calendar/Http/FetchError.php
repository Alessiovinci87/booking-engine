<?php

namespace App\Domain\Calendar\Http;

/** Motivi per cui SafeHttpFetcher rifiuta o interrompe un download (codici di SyncRun.error_code). */
enum FetchError: string
{
    case INVALID_URL = 'INVALID_URL';
    case SCHEME_NOT_ALLOWED = 'SCHEME_NOT_ALLOWED';
    case PORT_NOT_ALLOWED = 'PORT_NOT_ALLOWED';
    case DNS_FAILED = 'DNS_FAILED';
    case BLOCKED_ADDRESS = 'BLOCKED_ADDRESS';
    case TOO_MANY_REDIRECTS = 'TOO_MANY_REDIRECTS';
    case TOO_LARGE = 'TOO_LARGE';
    case TIMEOUT = 'TIMEOUT';
    case CONNECTION_FAILED = 'CONNECTION_FAILED';
    case TLS_ERROR = 'TLS_ERROR';
}

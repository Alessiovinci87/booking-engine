/**
 * Client dell'API del pannello (07): Sanctum SPA con cookie di sessione e token CSRF dal cookie
 * XSRF-TOKEN. Gli errori arrivano nel formato { error: { code, message, details, request_id } };
 * l'interfaccia si basa sempre su code.
 */

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string,
        public readonly details: Record<string, unknown> = {},
    ) {
        super(code);
    }
}

function readCookie(name: string): string | null {
    const prefix = `${name}=`;
    const entry = document.cookie.split('; ').find((part) => part.startsWith(prefix));

    return entry === undefined ? null : decodeURIComponent(entry.slice(prefix.length));
}

let csrfReady: Promise<void> | null = null;

/** Ottiene il cookie CSRF prima della prima richiesta che modifica lo stato. */
export function ensureCsrfCookie(): Promise<void> {
    csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined);

    return csrfReady;
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';

export interface RequestOptions {
    /** Versione per If-Match (07, concorrenza ottimistica): risposta 412 VERSION_MISMATCH se diversa. */
    version?: number;
    /** Idempotency-Key (07, I9): generata per ogni POST e riusata nel nuovo tentativo dopo CSRF_TOKEN_EXPIRED. */
    idempotencyKey?: string;
}

export async function apiRequest<T>(method: Method, path: string, body?: unknown, requestOptions: RequestOptions = {}): Promise<T> {
    const options: RequestOptions =
        method === 'POST' ? { ...requestOptions, idempotencyKey: requestOptions.idempotencyKey ?? crypto.randomUUID() } : requestOptions;

    try {
        return await send<T>(method, path, body, options);
    } catch (error) {
        // CSRF_TOKEN_EXPIRED (ADR-0008): nuovo cookie CSRF e un solo nuovo tentativo.
        if (error instanceof ApiError && error.code === 'CSRF_TOKEN_EXPIRED') {
            csrfReady = null;

            return send<T>(method, path, body, options);
        }
        throw error;
    }
}

async function send<T>(method: Method, path: string, body: unknown, options: RequestOptions): Promise<T> {
    if (method !== 'GET') {
        await ensureCsrfCookie();
    }

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const xsrf = readCookie('XSRF-TOKEN');

    if (xsrf !== null) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    if (options.idempotencyKey !== undefined) {
        headers['Idempotency-Key'] = options.idempotencyKey;
    }

    if (options.version !== undefined) {
        headers['If-Match'] = `"${options.version}"`;
    }

    // FormData (upload delle foto): il browser imposta Content-Type con il boundary.
    const isForm = body instanceof FormData;

    if (body !== undefined && !isForm) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(`/api/v1/admin${path}`, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        const error = (payload as { error?: { code?: string; details?: Record<string, unknown> } } | null)?.error;

        throw new ApiError(response.status, error?.code ?? 'SERVER_ERROR', error?.details ?? {});
    }

    return payload as T;
}

/**
 * Download di un file dell'API (export CSV o JSON, 6.2 e 6.3) con il cookie di sessione: come
 * apiRequest, ma restituisce il contenuto e il nome del file. Gli export chiedono la password
 * confermata (08): l'errore REAUTH_REQUIRED arriva come ApiError.
 */
export async function apiDownload(path: string, fallbackName: string): Promise<{ blob: Blob; filename: string }> {
    const response = await fetch(`/api/v1/admin${path}`, {
        method: 'GET',
        headers: { Accept: 'application/json, text/csv', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        const payload: unknown = await response.json().catch(() => null);
        const error = (payload as { error?: { code?: string; details?: Record<string, unknown> } } | null)?.error;

        throw new ApiError(response.status, error?.code ?? 'SERVER_ERROR', error?.details ?? {});
    }

    const disposition = response.headers.get('Content-Disposition') ?? '';
    const filename = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName;

    return { blob: await response.blob(), filename };
}

export interface SessionState {
    mfa_enabled: boolean;
    mfa_verified: boolean;
}

export interface Me {
    user: { id: string; email: string; locale: string };
    tenant: { id: string; name: string; slug: string; status: 'ACTIVE' | 'SUSPENDED' | 'OFFBOARDING' | 'CLOSED'; dpa_signed_at: string | null; dpa_version: string | null };
    /** null per un operatore di piattaforma in accesso di supporto (7.5). */
    role: string | null;
    support: { id: string; reason: string; expires_at: string } | null;
}

export const api = {
    session: () => apiRequest<SessionState>('GET', '/auth/session'),
    login: (email: string, password: string) =>
        apiRequest<{ mfa_setup_required: boolean }>('POST', '/auth/login', { email, password }),
    logout: () => apiRequest<void>('POST', '/auth/logout'),
    mfaSetup: () => apiRequest<{ qr_svg: string; secret: string }>('GET', '/auth/mfa/setup'),
    mfaConfirm: (code: string) => apiRequest<{ recovery_codes: string[] }>('POST', '/auth/mfa/setup', { code }),
    mfaVerify: (input: { code: string } | { recovery_code: string }) =>
        apiRequest<{ recovery_codes_remaining: number }>('POST', '/auth/mfa/verify', input),
    me: () => apiRequest<Me>('GET', '/me'),
    /** Ri-autenticazione per le azioni sensibili (08), valida 15 minuti. */
    confirmPassword: (password: string) => apiRequest<void>('POST', '/auth/confirm-password', { password }),
    /** Password dimenticata (6.11): risposta sempre identica, che l'email esista o no. */
    forgotPassword: (email: string) => apiRequest<{ status: string }>('POST', '/auth/password/forgot', { email }),
    resetPassword: (token: string, password: string, passwordConfirmation: string) =>
        apiRequest<void>('POST', '/auth/password/reset', { token, password, password_confirmation: passwordConfirmation }),
    changePassword: (currentPassword: string, password: string, passwordConfirmation: string) =>
        apiRequest<void>('POST', '/auth/password', {
            current_password: currentPassword,
            password,
            password_confirmation: passwordConfirmation,
        }),
};

/** Errori per campo di VALIDATION_FAILED (07: details.fields). */
export function fieldErrors(error: unknown): Record<string, string[]> {
    if (error instanceof ApiError && error.code === 'VALIDATION_FAILED') {
        const fields = error.details.fields;

        if (fields !== null && typeof fields === 'object') {
            return fields as Record<string, string[]>;
        }
    }

    return {};
}

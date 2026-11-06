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

export async function apiRequest<T>(method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
    try {
        return await send<T>(method, path, body);
    } catch (error) {
        // CSRF_TOKEN_EXPIRED (ADR-0008): nuovo cookie CSRF e un solo nuovo tentativo.
        if (error instanceof ApiError && error.code === 'CSRF_TOKEN_EXPIRED') {
            csrfReady = null;

            return send<T>(method, path, body);
        }
        throw error;
    }
}

async function send<T>(method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
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

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(`/api/v1/admin${path}`, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
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

export interface SessionState {
    mfa_enabled: boolean;
    mfa_verified: boolean;
}

export interface Me {
    user: { id: string; email: string; locale: string };
    tenant: { id: string; name: string; slug: string };
    role: string;
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
};

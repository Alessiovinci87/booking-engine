import { createHmac } from 'node:crypto';

/**
 * Codice TOTP (RFC 6238: SHA-1, 6 cifre, passo di 30 secondi) dal segreto base32 mostrato dal
 * pannello durante la configurazione della MFA. Solo per i test E2E: il server usa google2fa.
 */
export function totp(secret: string, now: number = Date.now()): string {
    const key = base32Decode(secret);
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 1000 / 30)));

    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1]! & 0x0f;
    const binary = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return String(binary).padStart(6, '0');
}

/** Secondi rimanenti nel passo corrente: vicino al cambio conviene aspettare il codice nuovo. */
export function secondsLeftInStep(now: number = Date.now()): number {
    return 30 - (Math.floor(now / 1000) % 30);
}

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const clean = input.replace(/[\s=]/g, '').toUpperCase();
    const bytes: number[] = [];
    let bits = 0;
    let value = 0;

    for (const char of clean) {
        const index = alphabet.indexOf(char);

        if (index === -1) {
            throw new Error('Segreto TOTP non valido');
        }

        value = (value << 5) | index;
        bits += 5;

        if (bits >= 8) {
            bytes.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return Buffer.from(bytes);
}

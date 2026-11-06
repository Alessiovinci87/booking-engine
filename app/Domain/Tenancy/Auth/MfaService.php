<?php

namespace App\Domain\Tenancy\Auth;

use App\Domain\Tenancy\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Cache\Repository as Cache;
use LogicException;
use PragmaRX\Google2FA\Google2FA;

/**
 * MFA TOTP obbligatoria per il pannello (08) con pragmarx/google2fa (ADR-0006). Il QR si genera in
 * locale con bacon/bacon-qr-code: il segreto non lascia mai il server.
 *
 * Un codice già usato non vale una seconda volta (protezione dal riuso nella stessa finestra).
 */
final class MfaService
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly Cache $cache,
    ) {}

    /**
     * Prepara un nuovo segreto per un utente che non ha ancora la MFA attiva. Il segreto resta non
     * attivo finché l'utente non conferma un codice valido.
     */
    public function beginSetup(User $user): string
    {
        if ($user->hasMfaEnabled()) {
            throw new LogicException('MFA già attiva: la configurazione non si ripete.');
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['mfa_secret_encrypted' => $secret])->save();

        return $secret;
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('security.mfa.issuer'), $user->email, $secret);
    }

    /** QR in SVG generato localmente. */
    public function qrSvg(string $otpauthUrl): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd));

        return $writer->writeString($otpauthUrl);
    }

    /**
     * Attiva la MFA se il codice corrisponde al segreto in configurazione.
     */
    public function confirmSetup(User $user, string $code): bool
    {
        if ($user->hasMfaEnabled() || $user->mfa_secret_encrypted === null) {
            return false;
        }

        if (! $this->verifyCode($user, $code)) {
            return false;
        }

        $user->forceFill(['mfa_enabled_at' => now()])->save();

        return true;
    }

    public function verify(User $user, string $code): bool
    {
        return $user->hasMfaEnabled() && $this->verifyCode($user, $code);
    }

    private function verifyCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        $secret = $user->mfa_secret_encrypted;

        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $key = 'mfa:last-timestep:'.$user->id;
        $last = $this->cache->get($key);
        // Con un valore precedente (anche 0) la libreria restituisce l'intervallo usato, non true.
        $timestep = $this->google2fa->verifyKeyNewer(
            $secret,
            $code,
            is_int($last) ? $last : 0,
            (int) config('security.mfa.window'),
        );

        if (! is_int($timestep)) {
            return false;
        }

        $this->cache->put($key, $timestep, 300);

        return true;
    }
}

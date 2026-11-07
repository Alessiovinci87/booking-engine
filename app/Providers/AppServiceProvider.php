<?php

namespace App\Providers;

use App\Domain\Calendar\Http\CurlTransport;
use App\Domain\Calendar\Http\HostResolver;
use App\Domain\Calendar\Http\HttpTransport;
use App\Domain\Calendar\Http\SystemHostResolver;
use App\Domain\Communications\MailPreviewBox;
use App\Domain\Communications\Transport\PreviewLogTransport;
use App\Domain\Communications\Transport\ResendTransport;
use App\Domain\Payments\Gateway\ConnectedAccounts;
use App\Domain\Payments\Gateway\Fake\FakeConnectedAccounts;
use App\Domain\Payments\Gateway\Fake\FakePaymentGateway;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Stripe\StripeClientFactory;
use App\Domain\Payments\Stripe\StripeConnectedAccounts;
use App\Domain\Payments\Stripe\StripePaymentGateway;
use App\Domain\Tenancy\Auth\PwnedPasswordsVerifier;
use App\Domain\Tenancy\MembershipTenantResolver;
use App\Domain\Tenancy\Outbox\AdminOpsPendingOutboxMessages;
use App\Domain\Tenancy\Outbox\PendingOutboxMessages;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un'istanza per richiesta o job: lo stato del tenant corrente non attraversa unità di lavoro.
        $this->app->scoped(TenantContext::class);

        // Pannello: tenant dalla membership dell'utente autenticato (08). Senza utente o membership
        // il risolutore restituisce null e il middleware risponde 404 (fail-closed). La superficie
        // pubblica usa PublicSlugTenantResolver tramite il middleware tenant:public.
        $this->app->scoped(TenantResolver::class, MembershipTenantResolver::class);

        // Dispatcher dell'outbox: lettura cross-tenant degli ID con app_admin_ops (08).
        $this->app->bind(PendingOutboxMessages::class, AdminOpsPendingOutboxMessages::class);
        // SafeHttpFetcher (5.1): DNS e trasporto sostituibili nei test.
        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(HttpTransport::class, CurlTransport::class);
        // Casella di anteprima delle email (6.1): attiva solo con APP_ENV=local.
        $this->app->singleton(MailPreviewBox::class, fn ($app): MailPreviewBox => new MailPreviewBox(
            $app, $app->make(Filesystem::class), (string) config('mail.preview.path'), (int) config('mail.preview.limit', 200),
        ));

        // Fornitore di pagamento (05), scelto con PAYMENT_GATEWAY: "stripe" (Stripe Connect) oppure
        // "fake" (checkout simulato, che rifiuta di partire fuori da local e testing).
        $this->app->singleton(StripeClientFactory::class, fn (): StripeClientFactory => new StripeClientFactory(config('services.stripe.secret')));

        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            return match (config('payments.gateway')) {
                'stripe' => $app->make(StripePaymentGateway::class),
                'fake' => new FakePaymentGateway($app['cache']->store(), $app->environment(), (string) config('app.url')),
                default => throw new LogicException('Gateway di pagamento non disponibile: '.var_export(config('payments.gateway'), true).'.'),
            };
        });

        $this->app->singleton(ConnectedAccounts::class, function ($app): ConnectedAccounts {
            return match (config('payments.gateway')) {
                'stripe' => new StripeConnectedAccounts($app->make(StripeClientFactory::class), (string) config('services.stripe.connect_country', 'it')),
                'fake' => new FakeConnectedAccounts($app->environment()),
                default => throw new LogicException('Gateway di pagamento non disponibile: '.var_export(config('payments.gateway'), true).'.'),
            };
        });

        // Solo sviluppo: `php artisan serve` passa al server PHP un elenco chiuso di variabili e su
        // Windows, senza TEMP/TMP, il server non riesce a creare i file temporanei degli upload
        // ("unable to create a temporary file"): le foto non si caricherebbero.
        if ($this->app->runningInConsole() && $this->app->environment('local')) {
            ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TEMP', 'TMP']));
        }
    }

    public function boot(): void
    {
        self::trustProxies(config('security.trusted_proxies'));

        // Trasporti delle email transazionali (6.1, ADR-0014).
        Mail::extend('preview-log', fn (array $config): PreviewLogTransport => new PreviewLogTransport(
            Log::channel($config['channel'] ?? null),
            $this->app->make(MailPreviewBox::class),
        ));
        Mail::extend('resend-api', fn (array $config): ResendTransport => new ResendTransport(
            isset($config['key']) && is_string($config['key']) ? $config['key'] : null,
            (string) ($config['endpoint'] ?? 'https://api.resend.com'),
            (int) ($config['timeout'] ?? 10),
        ));

        // Password compromesse: fail-open con avviso nel log (08, decisione dopo la verifica di M1).
        // extend e non bind: il provider di validazione è differito e sostituirebbe il binding.
        $this->app->extend(
            UncompromisedVerifier::class,
            fn (): UncompromisedVerifier => $this->app->make(PwnedPasswordsVerifier::class),
        );

        // API admin: 300 richieste al minuto per utente (07).
        RateLimiter::for('admin', function (Request $request): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perMinute((int) config('security.admin_rate_limit_per_minute'))
                ->by('admin:'.(is_string($id) ? $id : 'ip:'.$request->ip()));
        });

        // API pubblica in lettura: 120 richieste al minuto per IP (07).
        RateLimiter::for('public-read', fn (Request $request): Limit => Limit::perMinute((int) config('security.public_read_rate_limit_per_minute'))
            ->by('public-read:'.$request->ip()));

        // Feed iCal esportati: 60 richieste all'ora per token (06 "Formato"); il token entra nella chiave
        // solo come impronta.
        RateLimiter::for('ical', fn (Request $request): Limit => Limit::perHour((int) config('calendar.feed_rate_limit_per_hour'))
            ->by('ical:'.hash('sha256', (string) $request->route('token'))));

        // Creazione di prenotazioni dalla pagina ospite: per IP e per email (07). L'email entra nella
        // chiave del limite solo come impronta (09: nessun dato personale nella cache).
        RateLimiter::for('public-booking', function (Request $request): array {
            /** @var array{attempts: int, minutes: int} $limit */
            $limit = config('security.public_booking_rate_limit');
            $email = $request->input('guest.email');
            $limits = [Limit::perMinutes($limit['minutes'], $limit['attempts'])->by('public-booking:ip:'.$request->ip())];

            if (is_string($email) && $email !== '') {
                $limits[] = Limit::perMinutes($limit['minutes'], $limit['attempts'])->by('public-booking:email:'.hash('sha256', mb_strtolower(trim($email))));
            }

            return $limits;
        });
    }

    /**
     * Proxy fidati (6.7, `security.trusted_proxies`): IP del client e schema https dagli header
     * X-Forwarded-* solo se la richiesta arriva da uno di questi indirizzi. Vuoto = nessun proxy.
     */
    public static function trustProxies(mixed $configured): void
    {
        TrustProxies::flushState();
        $proxies = is_string($configured) ? trim($configured) : '';

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)), fn (string $p): bool => $p !== '')));
    }
}

# M6 — Revisione di sicurezza (attività 6.7, parte sul codice)

Data: 07/11/2026 · Ramo: `m6-rilascio` · Autore: Claude Code (da verificare con Alessio)

Revisione del codice e dell'ambiente locale contro 08 "Sicurezza e operatività", sezione per sezione. Non comprende il test di ripristino del backup (AC12, I10), l'infrastruttura (TLS, dischi, rete, segreti del server) e Sentry: richiedono gli account esterni di 6.4 e restano da verificare su staging, insieme alla configurazione di produzione elencata in fondo.

Esito: **5 lacune trovate e corrette** in questo ramo, ciascuna con un test (ADR-0014 punti 25–29, stato Proposta). **3 punti restano aperti**: due dipendono da 6.4, uno non è assegnato a nessuna milestone.

## Lacune corrette

| # | Sezione di 08 | Lacuna | Correzione | Test |
|---|---|---|---|---|
| S1 | Autenticazione e accesso ("export completo" fra le azioni sensibili) | L'export CSV delle prenotazioni del periodo, con l'anagrafica degli ospiti, e l'export JSON dei dati di un ospite (6.2 e 6.3) non chiedevano la password | Middleware `reauth` sulle due rotte. Il pannello scarica con fetch (`ProtectedDownload`); su `REAUTH_REQUIRED` chiede la password e riprova. Stessa cosa per la versione stampabile | `Feature/Metrics/MetricsTest`, `Feature/AnonymizationCoverageTest`, E2E `m6-acceptance` |
| S2 | Token pubblici ("Link prenotazione ospite: scadenza 90 giorni dopo il check-out") | Il link alla pagina della prenotazione (`GET /public/reservations/{code}?token=`) non scadeva mai | Dopo 90 giorni dal check-out risponde `404 NOT_FOUND`, uguale a un token sbagliato | `Feature/Public/PublicReservationApiTest` |
| S3 | Dati e cifratura ("HSTS sul dominio pubblico") | Nessun header `Strict-Transport-Security` | `SecurityHeaders` lo aggiunge sulle risposte https (`max-age=31536000; includeSubDomains`, `SECURITY_HSTS_MAX_AGE`) | `Feature/SecurityHeadersTest` |
| S4 | Rate limit per IP (07) e HSTS dietro un proxy | Nessun proxy fidato configurato. Dietro il load balancer di staging e produzione `request->ip()` sarebbe l'IP del proxy, quindi un solo contatore per tutti (login, prenotazioni, iCal), e ogni richiesta risulterebbe http | `TRUSTED_PROXIES` (`security.trusted_proxies`, `AppServiceProvider::trustProxies`): gli header `X-Forwarded-*` valgono solo se la richiesta arriva da questi indirizzi | `Feature/SecurityHeadersTest` (IP del client dietro proxy fidato, header ignorati da un IP non fidato) |
| S5 | Ambienti ("aggiornamenti di sicurezza settimanali (Dependabot)") | Configurazione di Dependabot assente | `.github/dependabot.yml`: composer, npm e GitHub Actions ogni lunedì, raggruppati, senza merge automatico | — (configurazione; `composer audit` e `npm audit` erano già in CI) |

## Verifiche senza lacune

| Sezione di 08 | Verifica | Riferimento |
|---|---|---|
| Multi-tenancy, livello applicativo | Tenant solo da membership, slug/token, account Stripe o record padre; un `tenant_id` nel corpo viene ignorato; risorsa di altro tenant → 404 | `Feature/TenantIsolationApiTest`, `Feature/Public/PublicReservationApiTest` ("ignora un tenant_id nel corpo") |
| Multi-tenancy, RLS | RLS abilitata e forzata su ogni tabella con `tenant_id`, policy fail-closed, `SET LOCAL` tramite `TenantContext`, dispatcher con `app_admin_ops` | `Feature/Architecture/RlsCatalogTest`, `Feature/CrossTenantInsertRejectedTest` |
| Integrità tra tenant | FK composite su tutte le relazioni tenant-scoped | `Feature/Architecture/CompositeFkCatalogTest` |
| Scritture di inventario | Solo `InventoryService` | `Arch/InventoryWritesTest` |
| Autenticazione | Cookie `Secure`, `HttpOnly` e `SameSite=Lax` (`config/session.php`), CSRF con `419 CSRF_TOKEN_EXPIRED`, Argon2id, minimo 12 caratteri, HIBP fail-open, MFA obbligatoria con codici di recupero e anti-replay, 2 h di inattività e 12 h assolute, ri-autenticazione di 15 minuti per rimborsi, Stripe e anonimizzazione | `Feature/Auth/*`, `Feature/Payments/RefundReauthTest` |
| Token pubblici | 32 byte, salvati come hash SHA-256; feed e preventivi cifrati con hash per la ricerca (funzioni SECURITY DEFINER); `Referrer-Policy: strict-origin-when-cross-origin`; nessun segreto negli URL | `Feature/Calendar/*`, `Feature/Quotes/*` |
| SSRF | Solo `SafeHttpFetcher` (https sulla porta 443, DNS controllato, reti private bloccate, redirect ricontrollati, limiti di dimensione e tempo). L'allowlist delle chiamate HTTP esterne è un test architetturale | `Unit/Calendar/SafeHttpFetcherTest`, `Arch/ExternalHttpTest` |
| Cifratura applicativa | Cast `encrypted` per URL iCal, segreti MFA, token dei feed e dei preventivi | modelli `ExternalCalendar`, `User`, `IcsFeed`, `Quote` |
| Dati di carta | Solo Stripe Checkout; nessun dato di carta sui nostri server | ADR-0011, ADR-0012 |
| Log | Nessun `Log::` con email, nomi o telefoni (ricerca nel codice); email mascherate nel trasporto (`RecipientMask`); payload dei webhook non salvati integralmente | `Feature/Communications/TransactionalMailTest`, `Feature/AuditLogNoPersonalDataTest` |
| Header | CSP con `default-src 'self'` e nonce, `frame-ancestors 'none'` (embed: solo le origini registrate), `nosniff`, API con `default-src 'none'`; nessun `dangerouslySetInnerHTML` nel frontend (il QR della MFA è un'immagine `data:`) | `Feature/SecurityHeadersTest` |
| Rotte di sviluppo | Checkout simulato solo con `PAYMENT_GATEWAY=fake` e `APP_ENV` local o testing; `/dev/mail` solo local; il gateway finto rifiuta di partire altrove | `routes/web.php`, `Feature/Communications/TransactionalMailTest` |
| Audit log | Append-only (`UPDATE` e `DELETE` revocati ad `app_runtime`), anche per `payment_events`; nessun valore personale | migration `2026_11_09_001000`, `2026_11_09_001100`; `Feature/AuditLogNoPersonalDataTest` |
| Dipendenze | `composer audit`: nessuna vulnerabilità; `npm audit`: 0 vulnerabilità (07/11/2026) | CI `composer audit`, `npm audit --audit-level=high` |
| Foto | Ricodificate in WebP sul server, limite per unità | `Feature/Admin/UnitPhotoApiTest` |

## Punti aperti

| # | Punto | Perché resta aperto | Quando |
|---|---|---|---|
| A1 | Test di ripristino del backup (AC12, I10), backup cifrati in una seconda regione | Servono il provider del database e lo storage di staging e produzione | 6.4 |
| A2 | TLS 1.2+, dischi cifrati, egress del solo worker di sync, segreti nel secret manager, Sentry | Infrastruttura | 6.4 |
| A3 | Accesso di supporto al tenant di un cliente (08: solo `app_admin_ops`, motivazione obbligatoria, durata limitata, audit visibile al cliente) | Non implementato e non assegnato a nessuna attività di 11. Nella 1.0 il supporto passa dal gestore (condivisione dello schermo) | Da decidere con Alessio: attività in M7 oppure ADR che lo rinvia dopo la 1.0 |

## Configurazione obbligatoria di staging e produzione (per 6.4)

Da controllare nella checklist di rilascio. I valori segreti vanno nel secret manager, mai nel repository.

- `APP_ENV=production` (o `staging`), `APP_DEBUG=false`, `LOG_LEVEL=info` o superiore, `APP_KEY` generata sul server.
- `APP_URL` in https; `TRUSTED_PROXIES` con gli indirizzi del load balancer; `SECURITY_HSTS_MAX_AGE=31536000`.
- `SESSION_SECURE_COOKIE=true` (predefinito), `SESSION_DOMAIN` e `SANCTUM_STATEFUL_DOMAINS` sul dominio del pannello.
- `PAYMENT_GATEWAY=stripe`, chiavi Stripe del rispettivo ambiente (test in staging), `STRIPE_WEBHOOK_SECRET`.
- `MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` sul dominio verificato; `MAIL_PREVIEW=false`.
- `ALERT_EMAILS` con gli indirizzi della piattaforma.
- `PLATFORM_PRIVACY_NOTICE_URL` e `PLATFORM_SUBPROCESSORS_URL` (testi legali di 09).
- Ruoli PostgreSQL `app_runtime`, `app_admin_ops` e `app_migrator` creati come in `docker/postgres/init` (`app_retention` arriva con 7.2); migration solo con `app_migrator`.

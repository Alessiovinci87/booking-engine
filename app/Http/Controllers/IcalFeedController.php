<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\Export\FeedUnavailable;
use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /ical/{token}.ics (06 "Formato", 07; 5.5). Il tenant si ricava dall'hash del token con la
 * funzione SECURITY DEFINER app_tenant_by_ics_feed; la lettura gira con app_runtime nel contesto del
 * tenant.
 *
 * - token sconosciuto → 404; RETIRED → 410 FEED_RETIRED;
 * - FROZEN senza elementi → 503 con Retry-After (mai un calendario vuoto, I19);
 * - `text/calendar; charset=utf-8`, `Cache-Control: no-store`, `ETag`; If-None-Match → 304.
 *
 * Il token non compare nei log.
 */
final class IcalFeedController extends Controller
{
    private const UNAVAILABLE_RETRY_SECONDS = 3600;

    public function __invoke(Request $request, string $token, TenantContext $tenants, IcsFeedLifecycle $lifecycle): Response
    {
        $hash = IcsFeedLifecycle::hashToken($token);
        $tenantId = DB::selectOne('SELECT app_tenant_by_ics_feed(?) AS id', [$hash])?->id;

        if (! is_string($tenantId)) {
            return ApiError::response(404, 'NOT_FOUND');
        }

        return $tenants->run($tenantId, function () use ($request, $hash, $lifecycle, $tenantId): Response {
            $feed = IcsFeed::query()->where('token_hash', $hash)->first();

            if ($feed === null) {
                return ApiError::response(404, 'NOT_FOUND');
            }

            try {
                $document = $lifecycle->serve($feed, $request->userAgent());
            } catch (BusinessRuleViolation $e) {
                return ApiError::response($e->httpStatus, $e->errorCode);
            } catch (FeedUnavailable) {
                Log::warning('calendar.frozen_feed_empty', ['tenant_id' => $tenantId, 'ics_feed_id' => $feed->id]);

                return ApiError::response(503, 'FEED_UNAVAILABLE', [], ['Retry-After' => (string) self::UNAVAILABLE_RETRY_SECONDS]);
            }

            $headers = ['Cache-Control' => 'no-store', 'ETag' => $document->etag];
            $ifNoneMatch = array_map('trim', explode(',', (string) $request->header('If-None-Match')));

            if (in_array($document->etag, $ifNoneMatch, true)) {
                return response('', 304, $headers);
            }

            return response($document->body, 200, [...$headers, 'Content-Type' => 'text/calendar; charset=utf-8']);
        });
    }
}

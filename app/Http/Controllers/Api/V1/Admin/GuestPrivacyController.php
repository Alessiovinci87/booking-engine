<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Privacy\GuestPrivacyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Diritti degli interessati (07 "Privacy", 09, 6.3):
 * - GET /admin/guests/{id}/export → JSON scaricabile con dati personali, prenotazioni, pagamenti e
 *   rimborsi; la versione stampabile (PDF dal browser) è nel pannello (ADR-0014);
 * - POST /admin/guests/{id}/erase → anonimizzazione immediata su tutte le copie (I22), con
 *   ri-autenticazione; `409 GUEST_HAS_ACTIVE_RESERVATION` su una prenotazione attiva non conclusa.
 */
final class GuestPrivacyController extends Controller
{
    public function export(string $guest, GuestPrivacyService $privacy): JsonResponse
    {
        $data = $privacy->export($guest);

        return new JsonResponse(['data' => $data], 200, [
            // Nome del file senza dati personali (09): solo l'identificativo.
            'Content-Disposition' => sprintf('attachment; filename="ospite-%s.json"', $data['guest']['id']),
            'Cache-Control' => 'no-store',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function erase(string $guest, GuestPrivacyService $privacy): JsonResponse
    {
        $erased = $privacy->erase($guest);

        return new JsonResponse(['data' => ['id' => $erased->id, 'anonymized_at' => $erased->anonymized_at?->toIso8601ZuluString()]]);
    }
}

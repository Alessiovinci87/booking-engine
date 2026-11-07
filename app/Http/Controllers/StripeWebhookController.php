<?php

namespace App\Http\Controllers;

use App\Domain\Payments\Stripe\StripeWebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * POST /webhooks/stripe (05 "Webhook", 07): eventi degli account connessi. Passo 1: firma verificata
 * con STRIPE_WEBHOOK_SECRET (tolleranza di 5 minuti di stripe-php); firma assente o non valida → 400.
 * I passi successivi sono di StripeWebhookReceiver; la risposta è sempre 200 dopo la registrazione,
 * così Stripe non ripete eventi già presi in carico.
 */
final class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookReceiver $receiver): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            Log::error('stripe.webhook_secret_missing');

            return new JsonResponse(['error' => ['code' => 'WEBHOOK_NOT_CONFIGURED']], 500);
        }

        $payload = $request->getContent();

        try {
            $event = Webhook::constructEvent($payload, (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            Log::warning('stripe.webhook_invalid_signature');

            return new JsonResponse(['error' => ['code' => 'INVALID_SIGNATURE']], 400);
        }

        return new JsonResponse(['received' => true, 'status' => $receiver->receive($event, $payload)->value]);
    }
}

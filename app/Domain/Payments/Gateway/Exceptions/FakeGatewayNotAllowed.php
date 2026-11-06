<?php

namespace App\Domain\Payments\Gateway\Exceptions;

use LogicException;

/** Il gateway finto e il checkout simulato esistono solo con APP_ENV local o testing. */
final class FakeGatewayNotAllowed extends LogicException
{
    public static function inEnvironment(string $environment): self
    {
        return new self("FakePaymentGateway non consentito nell'ambiente \"{$environment}\".");
    }
}

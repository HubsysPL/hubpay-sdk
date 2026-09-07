<?php

namespace Hubsys\HubPay\Sdk\Exception;

/**
 * Wyjątek rzucany przez HubPayClient przy błędach API lub problemach
 * z połączeniem. Pozwala łatwo złapać wszystkie błędy SDK jednym catchem.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

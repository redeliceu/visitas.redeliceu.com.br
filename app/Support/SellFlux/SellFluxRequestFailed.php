<?php

namespace App\Support\SellFlux;

use RuntimeException;

/**
 * The message never carries the URL or the response body: lookups put phone
 * numbers in the query string, and this exception may end up in the logs.
 * $reason is SellFlux's own short error text, kept apart for the admin screen.
 */
class SellFluxRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly ?int $status,
        public readonly ?string $reason = null,
    ) {
        parent::__construct(
            $status === null
                ? 'SellFlux request failed: connection error.'
                : "SellFlux request failed: HTTP {$status}.",
        );
    }

    public function isAuthenticationFailure(): bool
    {
        return in_array($this->status, [401, 403], true);
    }

    /**
     * Message for the admin screens, telling a refused token from a server error.
     */
    public function describeFor(string $label): string
    {
        if ($this->status === null) {
            return "Não foi possível conectar à SellFlux ao consultar {$label}. Tente novamente em instantes.";
        }

        $response = "HTTP {$this->status}".($this->reason !== null ? ": {$this->reason}" : '');

        return $this->isAuthenticationFailure()
            ? "A SellFlux recusou o token ao consultar {$label} ({$response}). Confira o token e as permissões dele."
            : "O token não foi recusado, mas a SellFlux retornou um erro ao consultar {$label} ({$response}).";
    }
}

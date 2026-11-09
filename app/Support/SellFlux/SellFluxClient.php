<?php

namespace App\Support\SellFlux;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only access to one SellFlux project. Each project has its own token.
 */
class SellFluxClient
{
    private const RETRIES = 3;

    private const RETRY_DELAY_MS = 500;

    public function __construct(private readonly string $token) {}

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     *
     * @throws SellFluxRequestFailed
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException) {
            throw new SellFluxRequestFailed(null);
        }

        if (! $response->successful()) {
            $reason = $response->json('msg');

            throw new SellFluxRequestFailed(
                $response->status(),
                is_string($reason) ? mb_substr($reason, 0, 200) : null,
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Project tokens get a 500 from /api/v1/project and the team routes, which
     * seem to need a user in the token, so the id comes from this route.
     *
     * @throws SellFluxRequestFailed
     */
    public function projectId(): ?string
    {
        $id = data_get($this->get('/api/v1/assistant/structure'), 'project_id');

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.sellflux.base_url'))
            ->withToken($this->token)
            ->acceptJson()
            ->timeout((int) config('services.sellflux.timeout'))
            ->retry(
                self::RETRIES,
                self::RETRY_DELAY_MS,
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && in_array($exception->response->status(), [429, 500, 502, 503, 504], true)),
                throw: false,
            );
    }
}

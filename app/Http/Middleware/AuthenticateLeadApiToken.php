<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateLeadApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('services.lead_api.token');
        $providedToken = $request->bearerToken();

        if (! is_string($configuredToken) || $configuredToken === '') {
            return response()->json(['message' => 'Serviço indisponível.'], 503);
        }

        if (! is_string($providedToken) || ! hash_equals($configuredToken, $providedToken)) {
            return response()
                ->json(['message' => 'Não autenticado.'], 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        return $next($request);
    }
}

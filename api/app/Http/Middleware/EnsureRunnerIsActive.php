<?php

namespace App\Http\Middleware;

use App\Models\Runner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRunnerIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $runner = $request->user();

        if (! $runner instanceof Runner) {
            abort(403, 'Token de runner inválido.');
        }

        $token = $runner->currentAccessToken();

        if ($token !== null && method_exists($token, 'can') && ! $token->can('runner')) {
            abort(403, 'Token sem permissão de runner.');
        }

        if ($runner->isRevoked()) {
            abort(403, 'Runner desativado ou revogado. Gere um novo token e atualize a configuração.');
        }

        return $next($request);
    }
}

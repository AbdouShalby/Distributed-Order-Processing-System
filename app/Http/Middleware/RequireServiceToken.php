<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.agent.token');

        if ($configured === null || $configured === '' || ! hash_equals($configured, $request->header('X-Service-Token', ''))) {
            return response()->json([
                'message' => 'Unauthorized.',
                'error_code' => 'UNAUTHORIZED',
            ], 401);
        }

        return $next($request);
    }
}

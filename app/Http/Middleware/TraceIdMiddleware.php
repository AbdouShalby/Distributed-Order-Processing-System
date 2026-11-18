<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generate or propagate a Trace ID for distributed tracing.
 * Adds X-Trace-Id to response headers and logging context.
 */
class TraceIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Trace-Id');
        if (! is_string($traceId) || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $traceId) !== 1) {
            $traceId = Str::uuid()->toString();
        }

        // Add to logging context so all logs in this request include trace_id
        Log::shareContext(['trace_id' => $traceId]);

        // Store in request for use in controllers/use cases
        $request->headers->set('X-Trace-Id', $traceId);

        /** @var Response $response */
        $response = $next($request);

        // Echo trace_id in response
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}

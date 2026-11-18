<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ValidateCustomerHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match('/\A[1-9]\d*\z/', $request->header('X-Customer-Id', '')) !== 1) {
            throw ValidationException::withMessages([
                'X-Customer-Id' => ['The X-Customer-Id header must be a positive integer without spaces or leading zeros.'],
            ]);
        }

        return $next($request);
    }
}

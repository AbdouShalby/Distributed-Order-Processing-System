<?php

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\LockAcquisitionException;
use App\Domain\Order\Exceptions\InvalidOrderTransitionException;
use App\Domain\Order\Exceptions\OrderNotCancellableException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Http\Middleware\TraceIdMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            TraceIdMiddleware::class,
        ]);

        $middleware->throttleApi('60,1'); // 60 requests per minute
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ─── Custom 429 Rate Limit Response ─────────────────
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Too many requests. Please slow down.',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                ], 429);
            }
        });

        // ─── Domain Exception → Structured JSON ────────────
        $exceptions->render(function (OrderNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Order not found.',
                    'error_code' => 'NOT_FOUND',
                ], 404);
            }
        });

        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => 'INSUFFICIENT_STOCK',
                ], 409);
            }
        });

        $exceptions->render(function (LockAcquisitionException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Could not acquire lock. Please retry.',
                    'error_code' => 'LOCK_CONFLICT',
                ], 409);
            }
        });

        $exceptions->render(function (OrderNotCancellableException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => 'INVALID_TRANSITION',
                ], 422);
            }
        });

        $exceptions->render(function (InvalidOrderTransitionException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => 'INVALID_TRANSITION',
                ], 422);
            }
        });

        // ─── Catch-all: mask unexpected errors in production ──
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (($request->expectsJson() || $request->is('api/*')) && ! app()->hasDebugModeEnabled()) {
                return response()->json([
                    'message' => 'An unexpected error occurred.',
                    'error_code' => 'INTERNAL_ERROR',
                ], 500);
            }
        });
    })->create();

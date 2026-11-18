<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AgentOrderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Middleware\ValidateCustomerHeader;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Distributed Order Processing System
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/health', HealthController::class);

// Products
Route::get('/products', [ProductController::class, 'index']);

// Orders
Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show'])->where('id', '[0-9]+');
Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->where('id', '[0-9]+');

Route::prefix('agent')
    ->withoutMiddleware('throttle:60,1')
    ->middleware(['service.token', 'throttle:agent'])
    ->group(function (): void {
        Route::middleware(ValidateCustomerHeader::class)->group(function (): void {
            Route::get('/orders', [AgentOrderController::class, 'index']);
            Route::get('/orders/{id}', [AgentOrderController::class, 'show'])->whereNumber('id');
        });
    });

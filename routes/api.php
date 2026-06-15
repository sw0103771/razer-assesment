<?php

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\TestingUiApiController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/payment-status', [PaymentWebhookController::class, 'handle']);

// Local tester helper endpoints used by public/tester.html.
// These are intentionally simple and should not be enabled in production.
Route::get('/testing/orders', [TestingUiApiController::class, 'orders']);
Route::post('/testing/orders', [TestingUiApiController::class, 'createOrder']);
Route::post('/testing/reset', [TestingUiApiController::class, 'reset']);
Route::get('/testing/logs', [TestingUiApiController::class, 'logs']);

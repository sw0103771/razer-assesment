<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TestingUiApiController extends Controller
{
    public function orders()
    {
        return response()->json([
            'orders' => Order::query()
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'merchant_id' => ['nullable', 'uuid'],
        ]);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $validated['merchant_id'] ?? (string) Str::uuid(),
            'amount' => (int) $validated['amount'],
            'currency' => strtoupper($validated['currency']),
            'status' => 'PENDING',
            'gateway_payment_id' => null,
            'idempotency_key' => null,
        ]);

        return response()->json([
            'message' => 'Test order created',
            'order' => $order,
        ], 201);
    }

    public function reset()
    {
        Order::query()->delete();

        $processedPaymentsLog = storage_path('app/processed_payments.log');
        $manualReviewLog = storage_path('logs/manual_review_orders.log');

        File::ensureDirectoryExists(dirname($processedPaymentsLog));
        File::ensureDirectoryExists(dirname($manualReviewLog));

        File::put($processedPaymentsLog, '');
        File::put($manualReviewLog, '');

        return response()->json([
            'message' => 'Orders and test logs reset',
        ]);
    }

    public function logs()
    {
        $processedPaymentsLog = storage_path('app/processed_payments.log');
        $manualReviewLog = storage_path('logs/manual_review_orders.log');

        return response()->json([
            'processed_payments' => file_exists($processedPaymentsLog)
                ? array_values(array_filter(array_map('trim', file($processedPaymentsLog))))
                : [],
            'manual_review' => file_exists($manualReviewLog)
                ? array_values(array_filter(array_map('trim', file($manualReviewLog))))
                : [],
        ]);
    }
}

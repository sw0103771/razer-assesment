<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON payload'], 400);
        }

        /*
         | Requirement 1: Webhook verification
         | We verify HMAC-SHA256 using the raw body and shared secret from .env.
         | Replay attack protection uses the timestamp field. The request is rejected
         | if timestamp is older/newer than 5 minutes from server time.
         | Unsigned requests are accepted only if they originate from 10.0.0.0/8.
         */
        $clientIp = $request->ip();
        $signature = $request->header('X-Gateway-Signature') ?? ($payload['signature'] ?? null);

        if (!$this->isInternalNetwork($clientIp)) {
            if (!$signature) {
                return response()->json(['message' => 'Missing signature'], 401);
            }

            if (!$this->isTimestampValid($payload['timestamp'] ?? null)) {
                return response()->json(['message' => 'Expired webhook timestamp'], 401);
            }

            if (!$this->isSignatureValid($rawBody, $payload, $signature)) {
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $eventType = $payload['event_type'] ?? null;
        $paymentId = $payload['payment_id'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? null;
        $metadata = $payload['metadata'] ?? [];
        $orderId = $metadata['order_id'] ?? null;
        $merchantId = $metadata['merchant_id'] ?? null;

        if (!$eventType || !$paymentId || !$orderId || !$merchantId || $amount === null || !$currency) {
            return response()->json(['message' => 'Missing required webhook fields'], 400);
        }

        /*
         | Requirement 2: Idempotency
         | We use payment_id as the deduplication key and store processed IDs in
         | storage/app/processed_payments.log, as requested by the assessment.
         */
        if ($this->hasPaymentAlreadyBeenProcessed($paymentId)) {
            return response()->json(['message' => 'Duplicate webhook ignored'], 200);
        }

        /*
         | Requirement 4: Concurrency safety
         | Locking strategy: pessimistic lock per order using Laravel Cache::lock().
         | If lock cannot be acquired, return 200 OK immediately to avoid gateway flood.
         */
        $lock = Cache::lock("payment_webhook_order_{$orderId}", 10);

        if (!$lock->get()) {
            Log::warning('Webhook skipped because order is already locked', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'Order is already being processed'], 200);
        }

        try {
            $order = Order::where('id', $orderId)
                ->where('merchant_id', $merchantId)
                ->first();

            if (!$order) {
                Log::warning('Webhook received for unknown order', [
                    'order_id' => $orderId,
                    'merchant_id' => $merchantId,
                    'payment_id' => $paymentId,
                ]);

                return response()->json(['message' => 'Order not found'], 404);
            }

            if ($order->currency !== $currency) {
                $this->flagForManualReview($order, $payload, 'Currency mismatch');
                return response()->json(['message' => 'Currency mismatch. Sent for manual review.'], 202);
            }

            return match ($eventType) {
                'payment.success' => $this->handlePaymentSuccess($order, $payload, $paymentId),
                'payment.failed' => $this->handlePaymentFailed($order, $payload, $paymentId),
                'refund.processed' => $this->handleRefundProcessed($order, $payload, $paymentId),
                default => response()->json(['message' => 'Unsupported event type'], 400),
            };
        } finally {
            optional($lock)->release();
        }
    }

    private function handlePaymentSuccess(Order $order, array $payload, string $paymentId)
    {
        /*
         | Requirement 5: Amount reconciliation
         | If amount differs, keep status unchanged and flag manual review.
         | Manual review means logging to Laravel log and manual_review_orders.log.
         */
        if ((int) $payload['amount'] !== (int) $order->amount) {
            $this->flagForManualReview($order, $payload, 'Amount mismatch');
            return response()->json(['message' => 'Amount mismatch. Sent for manual review.'], 202);
        }

        if (!$this->canTransition($order->status, 'PAID')) {
            $this->logInvalidTransition($order, 'PAID', $payload);
            return response()->json(['message' => 'Invalid status transition'], 409);
        }

        $order->update([
            'status' => 'PAID',
            'gateway_payment_id' => $payload['payment_id'],
            'idempotency_key' => $paymentId,
        ]);

        $this->markPaymentAsProcessed($paymentId);

        return response()->json(['message' => 'Order marked as paid'], 200);
    }

    private function handlePaymentFailed(Order $order, array $payload, string $paymentId)
    {
        if (!$this->canTransition($order->status, 'FAILED')) {
            $this->logInvalidTransition($order, 'FAILED', $payload);
            return response()->json(['message' => 'Invalid status transition'], 409);
        }

        $order->update([
            'status' => 'FAILED',
            'gateway_payment_id' => $payload['payment_id'],
            'idempotency_key' => $paymentId,
        ]);

        $this->markPaymentAsProcessed($paymentId);

        return response()->json(['message' => 'Order marked as failed'], 200);
    }

    private function handleRefundProcessed(Order $order, array $payload, string $paymentId)
    {
        /*
         | Requirement 6: Refund handling
         | Both full and partial refunds become REFUNDED because the gateway confirms
         | the refund was processed. Invalid refund amount goes to manual review.
         */
        if (!$this->canTransition($order->status, 'REFUNDED')) {
            $this->logInvalidTransition($order, 'REFUNDED', $payload);
            return response()->json(['message' => 'Invalid status transition'], 409);
        }

        $refundAmount = (int) $payload['amount'];

        if ($refundAmount <= 0 || $refundAmount > (int) $order->amount) {
            $this->flagForManualReview($order, $payload, 'Invalid refund amount');
            return response()->json(['message' => 'Invalid refund amount. Sent for manual review.'], 202);
        }

        $order->update([
            'status' => 'REFUNDED',
            'gateway_payment_id' => $payload['payment_id'],
            'idempotency_key' => $paymentId,
        ]);

        $this->markPaymentAsProcessed($paymentId);

        return response()->json(['message' => 'Order marked as refunded'], 200);
    }

    private function canTransition(string $currentStatus, string $newStatus): bool
    {
        $validTransitions = [
            'PENDING' => ['PAID', 'FAILED'],
            'PAID' => ['REFUNDED'],
            'FAILED' => [],
            'REFUNDED' => [],
        ];

        return in_array($newStatus, $validTransitions[$currentStatus] ?? [], true);
    }

    private function logInvalidTransition(Order $order, string $targetStatus, array $payload): void
    {
        Log::warning('Invalid order status transition detected', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'target_status' => $targetStatus,
            'payment_id' => $payload['payment_id'] ?? null,
            'event_type' => $payload['event_type'] ?? null,
        ]);
    }

    private function flagForManualReview(Order $order, array $payload, string $reason): void
    {
        Log::warning('Order flagged for manual review', [
            'reason' => $reason,
            'order_id' => $order->id,
            'current_status' => $order->status,
            'payment_id' => $payload['payment_id'] ?? null,
            'event_type' => $payload['event_type'] ?? null,
            'webhook_amount' => $payload['amount'] ?? null,
            'order_amount' => $order->amount,
        ]);

        $line = json_encode([
            'reason' => $reason,
            'order_id' => $order->id,
            'payment_id' => $payload['payment_id'] ?? null,
            'event_type' => $payload['event_type'] ?? null,
            'created_at' => now()->toDateTimeString(),
        ]);

        file_put_contents(storage_path('logs/manual_review_orders.log'), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function isSignatureValid(string $rawBody, array $payload, string $receivedSignature): bool
    {
        $secret = config('services.payment_gateway.webhook_secret');

        if (!$secret) {
            Log::error('Payment gateway webhook secret is not configured');
            return false;
        }

        /*
         | Normal production design: signature should be in X-Gateway-Signature,
         | and raw body is signed directly.
         |
         | Assessment payload includes signature inside JSON. If signature is inside
         | payload, remove it before calculating HMAC to avoid circular signing.
         */
        if (isset($payload['signature'])) {
            unset($payload['signature']);
            $bodyToSign = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $bodyToSign = $rawBody;
        }

        $expectedSignature = hash_hmac('sha256', $bodyToSign, $secret);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    private function isTimestampValid($timestamp): bool
    {
        if (!$timestamp || !is_numeric($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= 300;
    }

    private function isInternalNetwork(string $ip): bool
    {
        return str_starts_with($ip, '10.');
    }

    private function processedPaymentsFilePath(): string
    {
        return storage_path('app/processed_payments.log');
    }

    private function hasPaymentAlreadyBeenProcessed(string $paymentId): bool
    {
        $filePath = $this->processedPaymentsFilePath();

        if (!file_exists($filePath)) {
            return false;
        }

        $handle = fopen($filePath, 'r');

        if (!$handle) {
            Log::error('Unable to open processed payments log for reading');
            return false;
        }

        try {
            flock($handle, LOCK_SH);

            while (($line = fgets($handle)) !== false) {
                if (trim($line) === $paymentId) {
                    return true;
                }
            }

            return false;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function markPaymentAsProcessed(string $paymentId): void
    {
        $filePath = $this->processedPaymentsFilePath();
        $handle = fopen($filePath, 'a');

        if (!$handle) {
            Log::error('Unable to open processed payments log for writing');
            return;
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $paymentId . PHP_EOL);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        /*
         | File rotation strategy:
         | Run `php artisan processed-payments:rotate` daily/weekly via scheduler,
         | or rotate when the file reaches a max size. In production, replace this
         | file with a processed_webhooks DB table with payment_id UNIQUE.
         */
    }
}

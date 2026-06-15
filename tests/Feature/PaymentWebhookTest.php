<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function sign(array $payload): string
    {
        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            config('services.payment_gateway.webhook_secret')
        );
    }

    public function test_payment_success_marks_order_as_paid(): void
    {
        $order = Order::create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'merchant_id' => '22222222-2222-2222-2222-222222222222',
            'amount' => 10000,
            'currency' => 'MYR',
            'status' => 'PENDING',
        ]);

        $payload = [
            'event_type' => 'payment.success',
            'payment_id' => 'pay_001',
            'amount' => 10000,
            'currency' => 'MYR',
            'metadata' => [
                'order_id' => $order->id,
                'merchant_id' => $order->merchant_id,
            ],
            'timestamp' => time(),
        ];

        $response = $this->withHeaders([
            'X-Gateway-Signature' => $this->sign($payload),
        ])->postJson('/api/webhooks/payment-status', $payload);

        $response->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PAID',
            'gateway_payment_id' => 'pay_001',
        ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payload = [
            'event_type' => 'payment.success',
            'payment_id' => 'pay_bad',
            'amount' => 10000,
            'currency' => 'MYR',
            'metadata' => [
                'order_id' => '11111111-1111-1111-1111-111111111111',
                'merchant_id' => '22222222-2222-2222-2222-222222222222',
            ],
            'timestamp' => time(),
        ];

        $response = $this->withHeaders([
            'X-Gateway-Signature' => 'wrong-signature',
        ])->postJson('/api/webhooks/payment-status', $payload);

        $response->assertStatus(401);
    }

    public function test_amount_mismatch_does_not_mark_paid(): void
    {
        $order = Order::create([
            'id' => '33333333-3333-3333-3333-333333333333',
            'merchant_id' => '44444444-4444-4444-4444-444444444444',
            'amount' => 10000,
            'currency' => 'MYR',
            'status' => 'PENDING',
        ]);

        $payload = [
            'event_type' => 'payment.success',
            'payment_id' => 'pay_mismatch',
            'amount' => 9000,
            'currency' => 'MYR',
            'metadata' => [
                'order_id' => $order->id,
                'merchant_id' => $order->merchant_id,
            ],
            'timestamp' => time(),
        ];

        $response = $this->withHeaders([
            'X-Gateway-Signature' => $this->sign($payload),
        ])->postJson('/api/webhooks/payment-status', $payload);

        $response->assertStatus(202);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PENDING',
        ]);
    }
}

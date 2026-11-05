<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentWebhookOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.payment.webhook_secret' => 'test-secret',
        ]);
    }

    public function test_payment_success_records_only_one_event_for_duplicate_callbacks(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $payment = $this->createPayment();
        $payload = [
            'payment_reference' => $payment->payment_reference,
            'gateway_transaction_id' => 'txn-123',
            'status' => 'SUCCESS',
            'paid_at' => now()->subMinute()->toIso8601String(),
        ];

        $this->sendWebhook('/api/payments/webhook', $payload)
            ->assertOk();
        $this->sendWebhook('/api/payments/webhook', $payload)
            ->assertOk();

        $this->assertDatabaseCount('outbox_events', 1);
        $event = OutboxEvent::firstOrFail();
        $this->assertSame('payment.succeeded', $event->topic);
        $this->assertSame('payment.succeeded', $event->payload['event_type']);
        $this->assertSame($payment->id, $event->payload['data']['payment_id']);
        Http::assertSentCount(2);
    }

    public function test_failed_payment_records_a_failure_event(): void
    {
        $payment = $this->createPayment();

        $this->sendWebhook('/api/payments/webhook', [
            'payment_reference' => $payment->payment_reference,
            'gateway_transaction_id' => 'txn-failed',
            'status' => 'FAILED',
        ])->assertOk();

        $event = OutboxEvent::firstOrFail();
        $this->assertSame('payment.failed', $event->topic);
        $this->assertSame($payment->id, $event->payload['data']['payment_id']);
    }

    public function test_rejected_booking_confirmation_creates_only_one_pending_automatic_refund(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Expired'], 409)]);
        $payment = $this->createPayment();
        $payload = [
            'payment_reference' => $payment->payment_reference,
            'gateway_transaction_id' => 'txn-expired-booking',
            'status' => 'SUCCESS',
            'paid_at' => now()->subMinute()->toIso8601String(),
        ];

        $this->sendWebhook('/api/payments/webhook', $payload)
            ->assertStatus(202);
        $this->sendWebhook('/api/payments/webhook', $payload)
            ->assertOk();

        $payment->refresh();
        $this->assertSame('REFUND_PENDING', $payment->status);
        $this->assertDatabaseCount('refunds', 1);
        $refund = Refund::firstOrFail();
        $this->assertSame('PENDING', $refund->status);
        $this->assertSame('500.00', $refund->amount);
        Http::assertSentCount(1);
    }

    public function test_successful_refund_records_only_one_event_for_duplicate_callbacks(): void
    {
        $payment = $this->createPayment('REFUND_PENDING');
        $refund = Refund::create([
            'payment_id' => $payment->id,
            'refund_reference' => 'REF-123',
            'amount' => 100,
            'status' => 'PENDING',
        ]);
        $payload = [
            'refund_reference' => $refund->refund_reference,
            'gateway_refund_id' => 'gateway-refund-123',
            'status' => 'SUCCESS',
        ];

        $this->sendWebhook('/api/payments/refund-webhook', $payload)
            ->assertOk();
        $this->sendWebhook('/api/payments/refund-webhook', $payload)
            ->assertOk();

        $this->assertDatabaseCount('outbox_events', 1);
        $event = OutboxEvent::firstOrFail();
        $this->assertSame('payment.refunded', $event->topic);
        $this->assertSame($refund->id, $event->payload['data']['refund_id']);
    }

    private function createPayment(string $status = 'PENDING'): Payment
    {
        return Payment::create([
            'booking_id' => 10,
            'user_id' => 20,
            'payment_reference' => 'PAY-'.uniqid(),
            'idempotency_key' => 'idem-'.uniqid(),
            'amount' => 500,
            'currency' => 'INR',
            'status' => $status,
        ]);
    }

    private function sendWebhook(string $uri, array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret'),
        ], $body);
    }
}

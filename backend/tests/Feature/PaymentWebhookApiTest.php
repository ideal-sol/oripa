<?php

namespace Tests\Feature;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointType;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_webhook_grants_points(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, 'mock_success_payment');

        $response = $this->postSignedWebhook([
            'event_id' => 'evt_success_1',
            'type' => 'payment.succeeded',
            'provider' => 'mock',
            'provider_payment_id' => $payment->provider_payment_id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('payment_id', $payment->id)
            ->assertJsonPath('status', 'succeeded');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Succeeded->value,
            'webhook_event_id' => 'evt_success_1',
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 1000,
            'free_balance' => 100,
        ]);
        $this->assertDatabaseHas('point_lots', [
            'user_id' => $user->id,
            'point_type' => PointType::Paid->value,
            'granted_amount' => 1000,
            'remaining_amount' => 1000,
            'expire_at' => null,
        ]);
        $this->assertDatabaseHas('point_lots', [
            'user_id' => $user->id,
            'point_type' => PointType::Free->value,
            'granted_amount' => 100,
            'remaining_amount' => 100,
        ]);
    }

    public function test_duplicate_success_webhook_does_not_grant_points_twice(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, 'mock_duplicate_payment');
        $payload = [
            'event_id' => 'evt_duplicate_1',
            'type' => 'payment.succeeded',
            'provider' => 'mock',
            'provider_payment_id' => $payment->provider_payment_id,
        ];

        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('duplicate', false);
        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 1000,
            'free_balance' => 100,
        ]);
        $this->assertDatabaseCount('point_lots', 2);
        $this->assertDatabaseCount('point_ledgers', 2);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payment = $this->createPayment(User::factory()->create(), 'mock_bad_signature_payment');
        $payload = json_encode([
            'event_id' => 'evt_bad_signature',
            'type' => 'payment.succeeded',
            'provider' => 'mock',
            'provider_payment_id' => $payment->provider_payment_id,
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MOCK_SIGNATURE' => 'invalid',
        ], $payload)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid webhook signature.');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Pending->value,
        ]);
        $this->assertDatabaseCount('point_lots', 0);
    }

    public function test_failed_webhook_marks_payment_failed_without_point_grant(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, 'mock_failed_payment');

        $this->postSignedWebhook([
            'event_id' => 'evt_failed_1',
            'type' => 'payment.failed',
            'provider' => 'mock',
            'provider_payment_id' => $payment->provider_payment_id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Failed->value,
            'webhook_event_id' => 'evt_failed_1',
        ]);
        $this->assertDatabaseCount('point_lots', 0);
        $this->assertDatabaseMissing('wallets', [
            'user_id' => $user->id,
        ]);
    }

    public function test_webhook_rejects_unknown_payment(): void
    {
        $this->postSignedWebhook([
            'event_id' => 'evt_unknown_1',
            'type' => 'payment.succeeded',
            'provider' => 'mock',
            'provider_payment_id' => 'missing_payment',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Payment was not found.');
    }

    private function createPayment(User $user, string $providerPaymentId): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => $providerPaymentId,
            'status' => PaymentStatus::Pending,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'currency' => 'JPY',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postSignedWebhook(array $payload)
    {
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $content, (string) config('oripa.payment.mock_webhook_secret'));

        return $this->call('POST', '/api/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MOCK_SIGNATURE' => $signature,
        ], $content);
    }
}

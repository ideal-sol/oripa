<?php

namespace App\Domain\Notification\Services;

use App\Models\ContactRequest;
use App\Models\Payment;
use App\Models\ShippingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordNotificationService
{
    public function __construct(private readonly AdminDiscordMessageFormatter $formatter)
    {
    }

    public function notifyContactReceived(ContactRequest $contactRequest): bool
    {
        return $this->sendToAdmin($this->formatter->contactReceived($contactRequest));
    }

    public function notifyShippingRequested(ShippingRequest $shippingRequest): bool
    {
        return $this->sendToAdmin($this->formatter->shippingRequested($shippingRequest));
    }

    public function notifyPaymentSucceeded(Payment $payment): bool
    {
        return $this->sendToAdmin($this->formatter->paymentSucceeded($payment));
    }

    public function sendToAdmin(string $content): bool
    {
        $webhookUrl = config('services.discord.admin_webhook_url');

        if (! is_string($webhookUrl) || trim($webhookUrl) === '') {
            Log::info('Discord admin webhook is not configured. Notification skipped.', [
                'preview' => mb_substr($content, 0, 120),
            ]);

            return false;
        }

        $response = Http::timeout(10)->post($webhookUrl, [
            'content' => $content,
            'allowed_mentions' => [
                'parse' => [],
            ],
        ]);

        if ($response->failed()) {
            Log::warning('Discord admin notification failed.', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
        }

        return $response->successful();
    }
}

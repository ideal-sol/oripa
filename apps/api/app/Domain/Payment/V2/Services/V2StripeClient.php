<?php

namespace App\Domain\Payment\V2\Services;

use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class V2StripeClient
{
    private function sdk(): StripeClient
    {
        app(V2StripeConfiguration::class)->assertConfigured();
        $transport = new CurlClient();
        $transport->setTimeout((int) config('v2_stripe.create_timeout_seconds'));
        $transport->setConnectTimeout((int) config('v2_stripe.create_timeout_seconds'));
        ApiRequestor::setHttpClient($transport);

        return new StripeClient([
            'api_key' => config('v2_stripe.secret_key'),
            'stripe_version' => V2StripeConfiguration::API_VERSION,
        ]);
    }

    public function create(array $parameters, string $key): array
    {
        return $this->sdk()->checkout->sessions->create($parameters, ['idempotency_key' => $key, 'max_network_retries' => 0])->toArray();
    }

    public function session(string $id): array
    {
        return $this->sdk()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent.latest_charge']])->toArray();
    }

    public function expire(string $id): void
    {
        $this->sdk()->checkout->sessions->expire($id, []);
    }

    public function charge(string $id): array
    {
        $client = $this->sdk();
        $charge = $client->charges->retrieve($id, ['expand' => ['refunds']])->toArray();
        if ($charge['refunds']['has_more'] ?? false) {
            $ids = [];
            foreach ($client->refunds->all(['charge' => $id, 'limit' => 100])->autoPagingIterator() as $refund) {
                $ids[] = ['id' => $refund->id];
            }
            $charge['refunds']['data'] = $ids;
        }
        return $charge;
    }

    public function dispute(string $id): array
    {
        return $this->sdk()->disputes->retrieve($id)->toArray();
    }
}

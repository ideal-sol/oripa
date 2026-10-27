<?php

namespace App\Domain\Payment\V2\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class V2UnivapayClient
{
    public function charge(string $id, bool $polling = false): ?array
    {
        if (! Str::isUuid($id)) return null;

        return $this->get('/charges/'.$id, $polling ? ['polling' => 'true'] : []);
    }

    /** List is discovery only. Every returned ID must subsequently pass an exact GET. */
    public function candidates(object $payment): ?array
    {
        $ids = [];
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $result = $this->get('/charges', array_filter([
                'from' => CarbonImmutable::parse($payment->created_at)->utc()->toIso8601String(),
                'amount_from' => (int) $payment->amount - 1, 'amount_to' => (int) $payment->amount + 1,
                'mode' => config('v2_univapay.mode'), 'limit' => 100, 'cursor' => $cursor,
                'cursor_direction' => 'asc',
            ], static fn ($value) => $value !== null));
            if ($result === null || ! is_array($result['items'] ?? null)) return null;
            foreach ($result['items'] as $item) {
                if (is_array($item) && ($item['metadata']['oripa-payment-id'] ?? null) === $payment->public_id
                    && is_string($item['id'] ?? null) && Str::isUuid($item['id'])) $ids[] = $item['id'];
            }
            if (($result['has_more'] ?? false) === false && count($result['items']) < 100) return array_values(array_unique($ids));
            $next = end($result['items'])['id'] ?? null;
            if (! is_string($next) || ! Str::isUuid($next) || $next === $cursor) return null;
            $cursor = $next;
        }

        return null; // Incomplete discovery is uncertainty, never proof of payment failure.
    }

    private function get(string $path, array $query): ?array
    {
        if (! app(V2UnivapayConfiguration::class)->available()) return null;
        try {
            $response = Http::acceptJson()->withToken(config('v2_univapay.api_secret').'.'.config('v2_univapay.api_app_token'))
                ->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->get('https://api.univapay.com/stores/'.config('v2_univapay.store_id').$path, $query);
            if (! $response->successful()) return null;
            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (ConnectionException) {
            // Do not log an exception containing request headers or response bodies.
            return null;
        }
    }
}

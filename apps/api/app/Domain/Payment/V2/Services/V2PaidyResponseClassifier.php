<?php

namespace App\Domain\Payment\V2\Services;

use Carbon\CarbonImmutable;

final class V2PaidyResponseClassifier
{
    /** Exact JPY normalization, including documented doubles and decimal strings. */
    public static function yen(mixed $value): ?int
    {
        if (is_int($value)) return $value >= 0 && $value <= 2147483647 ? $value : null;
        if (is_float($value)) return is_finite($value) && $value >= 0 && $value <= 2147483647 && floor($value) === $value ? (int) $value : null;
        if (! is_string($value) || ! preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.0+)?\z/', $value, $parts)) return null;
        return (int) $parts[1] <= 2147483647 ? (int) $parts[1] : null;
    }

    public function classify(?array $body, object $payment, string $id, bool $test): array
    {
        $unknown = ['state' => 'uncertain', 'match' => 'ok', 'uncaptured' => false, 'occurred_at' => null];
        if ($body === null || ($body['id'] ?? null) !== $id || ! is_string($body['status'] ?? null)
            || ! is_bool($body['test'] ?? null) || ! is_array($body['order'] ?? null)
            || ! is_array($body['captures'] ?? null) || ! array_is_list($body['captures'])
            || ! is_array($body['refunds'] ?? null) || ! array_is_list($body['refunds'])
            || self::yen($body['amount'] ?? null) === null) return $unknown;
        $status = strtolower($body['status']);
        $uncaptured = $status === 'authorized' && $body['captures'] === [] && $body['refunds'] === [];
        $match = match (true) {
            ($body['currency'] ?? null) !== 'JPY', self::yen($body['amount']) !== (int) $payment->amount => 'amount_mismatch',
            ($body['order']['order_ref'] ?? null) !== $payment->public_id => 'member_mismatch',
            $body['test'] !== $test => 'mode_mismatch',
            default => 'ok',
        };
        if ($match !== 'ok') return [...$unknown, 'match' => $match, 'uncaptured' => $uncaptured];
        if ($uncaptured) return [...$unknown, 'state' => 'authorized', 'uncaptured' => true];
        if ($body['refunds'] !== []) return [...$unknown, 'state' => 'reversed'];
        if ($status === 'rejected' && $body['captures'] === []) return [...$unknown, 'state' => 'failed'];
        if ($status !== 'closed') return $unknown;
        if ($body['captures'] === []) return [...$unknown, 'state' => 'failed'];
        $total = 0; $ids = []; $occurred = null;
        foreach ($body['captures'] as $capture) {
            if (! is_array($capture) || ! is_string($capture['id'] ?? null)
                || ! preg_match('/\Acap_[A-Za-z0-9_-]{1,124}\z/', $capture['id'])
                || in_array($capture['id'], $ids, true) || self::yen($capture['amount'] ?? null) === null
                || ! is_string($capture['created_at'] ?? null)
                || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z\z/', $capture['created_at'])) return $unknown;
            try { $date = CarbonImmutable::parse($capture['created_at']); }
            catch (\Exception) { return $unknown; }
            if ($date->isFuture() || $date->lessThan(CarbonImmutable::parse($payment->created_at)->startOfSecond())) return $unknown;
            $occurred = $occurred === null || $date->greaterThan($occurred) ? $date : $occurred;
            $ids[] = $capture['id']; $total += self::yen($capture['amount']);
        }
        return [...$unknown, 'state' => $total === (int) $payment->amount ? 'captured' : 'mismatch', 'occurred_at' => $occurred];
    }
}

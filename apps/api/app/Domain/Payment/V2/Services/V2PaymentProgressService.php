<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PaymentProgressService
{
    private const CLIENT_RESULTS = [
        'redirect_started' => [], 'inline_submitted' => [], 'paidy_checkout_opened' => [],
        'inline_result' => ['success', 'error'],
        'paidy_checkout_result' => ['authorized', 'rejected', 'closed'],
        'returned' => ['complete', 'cancel', 'failure'],
    ];

    public function client(User $user, string $paymentId, array $input): void
    {
        if (! Str::isUuid($paymentId)) {
            throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');
        }
        DB::transaction(function () use ($user, $paymentId, $input): void {
            $payment = DB::table('payments')->where('public_id', $paymentId)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($payment === null) {
                throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');
            }
            $type = $input['type'] ?? null;
            $detail = $input['detail'] ?? [];
            if (! is_string($type) || ! array_key_exists($type, self::CLIENT_RESULTS)
                || array_diff(array_keys($input), ['type', 'detail']) || ! is_array($detail)
                || array_diff(array_keys($detail), ['result', 'charge_id'])
                || (array_key_exists('charge_id', $detail) && ($type !== 'inline_result' || ! is_string($detail['charge_id']) || ! Str::isUuid($detail['charge_id'])))
                || (array_key_exists('result', $detail) && ! in_array($detail['result'], self::CLIENT_RESULTS[$type], true))) {
                throw new V2FincodeException('PAYMENT_CLIENT_EVENT_INVALID', 422, 'The client event is invalid.');
            }
            if (DB::table('payment_progress_events')->where('payment_id', $payment->id)
                ->where('type', $type)->where('source', 'client')->count() >= 3) {
                return;
            }
            $this->append((int) $payment->id, $type, $detail, 'client');
        }, 3);
    }

    public function append(int $paymentId, string $type, array $detail = [], string $source = 'server'): void
    {
        // Only bounded enums and numeric money are accepted; never arbitrary provider/browser text.
        if (array_diff(array_keys($detail), ['result', 'amount', 'method', 'provider', 'charge_id', 'match', 'operation', 'http_status', 'reason', 'error_code'])
            || (isset($detail['error_code']) && (! is_int($detail['error_code']) || $detail['error_code'] < 0 || $detail['error_code'] > 999999))
            || (isset($detail['result']) && ! in_array($detail['result'], ['success','error','authorized','rejected','closed','complete','cancel','failure'], true))
            || (isset($detail['amount']) && (! is_int($detail['amount']) || $detail['amount'] < 0))
            || (isset($detail['method']) && ! isset(V2PaymentMethodPolicy::LABELS[$detail['method']]))
            || (isset($detail['provider']) && (! is_string($detail['provider']) || ! preg_match('/\A[a-z0-9._-]{1,64}\z/', $detail['provider'])))
            || (isset($detail['charge_id']) && (! is_string($detail['charge_id']) || ! Str::isUuid($detail['charge_id'])))
            || (isset($detail['match']) && ! in_array($detail['match'], ['ok','amount_mismatch','member_mismatch','source_mismatch','mode_mismatch','expired','duplicate_charge'], true))
            || (isset($detail['operation']) && ! in_array($detail['operation'], ['lookup','capture','cancel','refund'], true))
            || (isset($detail['http_status']) && (! is_int($detail['http_status']) || $detail['http_status'] < 100 || $detail['http_status'] > 599))
            || (isset($detail['reason']) && ! in_array($detail['reason'], ['provider_event','provider_api','system','user','timeout','reconciliation_failed','capture_grant_failed','provider_reversed'], true))) {
            throw new \InvalidArgumentException('Payment progress detail is invalid.');
        }
        $days = filter_var(config('v2_payment.event_retention_days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650) {
            throw new \LogicException('Payment progress retention is invalid.');
        }
        $now = CarbonImmutable::now('UTC');
        DB::table('payment_progress_events')->insert([
            'payment_id' => $paymentId, 'type' => $type, 'source' => $source,
            'detail' => json_encode((object) $detail, JSON_THROW_ON_ERROR),
            'recorded_at' => V2DatabaseTimestamp::format($now),
            'retain_until' => V2DatabaseTimestamp::format($now->addDays($days)),
        ]);
    }

    public function timeline(int $paymentId): array
    {
        return DB::table('payment_progress_events')->where('payment_id', $paymentId)->orderBy('recorded_at')->orderBy('id')
            ->get()->map(fn ($row) => [
                'type' => $row->type, 'source' => $row->source,
                'detail' => json_decode($row->detail, false, 512, JSON_THROW_ON_ERROR),
                'recorded_at' => CarbonImmutable::parse($row->recorded_at)->utc()->toIso8601String(),
            ])->all();
    }

    public function purge(): int
    {
        return DB::transaction(function (): int {
            DB::statement("SET LOCAL oripa.payment_progress_purge = 'on'");

            return DB::table('payment_progress_events')->where('retain_until', '<', V2DatabaseTimestamp::format(now()))->delete();
        });
    }
}

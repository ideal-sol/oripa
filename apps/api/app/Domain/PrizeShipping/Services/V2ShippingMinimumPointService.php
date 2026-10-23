<?php

namespace App\Domain\PrizeShipping\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2ShippingMinimumPointService
{
    public const MAX_POINTS = 9_007_199_254_740_991;
    public const MAX_TOTAL = 900_719_925_474_099_100;

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function policy(): array
    {
        $setting = $this->setting();

        return [
            'minimum_points' => (string) $setting->minimum_points,
            'effective_required_points' => (string) max(1, (int) $setting->minimum_points),
            'revision' => (int) $setting->revision,
            'zero_total_allowed' => false,
        ];
    }

    public function read(V2AdminAuthorizationContext $context): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadShippingSettings);

        return $this->serialize($this->setting());
    }

    /** Locks only after User, Wallet and ordered User Prizes. Admin updates never acquire those locks. */
    public function shippingSnapshot(iterable $prizes): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Shipping policy requires the owning transaction.');
        }
        $setting = $this->setting(true);
        $total = 0;
        $count = 0;
        foreach ($prizes as $prize) {
            $points = $this->integer($prize->exchange_point_snapshot, self::MAX_POINTS);
            if ($points === null || ++$count > 100 || $total > self::MAX_TOTAL - $points) {
                throw $this->unavailable();
            }
            $total += $points;
        }
        $minimum = (int) $setting->minimum_points;
        $required = max(1, $minimum);
        if ($total < $required) {
            throw new V2PrizeShippingException(
                'SHIPPING_MINIMUM_POINTS_NOT_MET', 409,
                '選択景品の合計ポイントが発送条件を満たしていません。合計0ptの発送はできません。',
                false,
                [
                    'minimum_points' => (string) $minimum,
                    'effective_required_points' => (string) $required,
                    'selected_total_points' => (string) $total,
                    'shortfall_points' => (string) ($required - $total),
                    'setting_revision' => (int) $setting->revision,
                ]
            );
        }

        return [
            'selected_point_total_snapshot' => $total,
            'applied_minimum_points_snapshot' => $minimum,
            'applied_setting_revision' => (int) $setting->revision,
        ];
    }

    public function update(V2AdminAuthorizationContext $context, string $key, array $input): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManageShippingSettings);
        if (strlen($key) < 16 || strlen($key) > 128) {
            throw new V2PrizeShippingException('INVALID_IDEMPOTENCY_KEY', 422, 'The Idempotency Key is invalid.');
        }
        if (count($input) !== 2 || ! is_string($input['minimum_points'] ?? null)
            || $this->integer($input['minimum_points'], self::MAX_POINTS) === null
            || ! is_int($input['expected_revision'] ?? null)
            || $input['expected_revision'] < 1 || $input['expected_revision'] > self::MAX_POINTS) {
            throw new V2PrizeShippingException('SHIPPING_SETTING_INVALID', 422, 'The shipping setting is invalid.');
        }
        try {
            return DB::transaction(function () use ($context, $admin, $key, $input): array {
                $claim = $this->idempotency->claim('shipping.settings.update', 'admin', $admin->public_id, $key, $input);
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! is_array($response['data'] ?? null)) throw $this->unavailable();

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }
                $setting = $this->setting(true, true);
                if ((int) $setting->revision !== $input['expected_revision']) {
                    throw new V2PrizeShippingException('SHIPPING_SETTING_REVISION_CONFLICT', 409, '設定が更新されました。最新値を再取得してください。');
                }
                if ((string) $setting->minimum_points !== $input['minimum_points']) {
                    if ((int) $setting->revision >= self::MAX_POINTS) throw $this->unavailable();
                    $before = $this->serialize($setting);
                    DB::table('shipping_minimum_point_settings')->where('id', 1)->update([
                        'minimum_points' => (int) $input['minimum_points'],
                        'revision' => (int) $setting->revision + 1,
                        'updated_by_admin_id' => $admin->getKey(),
                        'updated_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                    ]);
                    $setting = $this->setting();
                    $this->audit->record('shipping.settings.updated', [
                        'request_id' => $context->requestId,
                        'actor_type' => 'admin', 'actor_public_id' => $admin->public_id,
                        'actor_role' => $admin->role->value, 'auth_realm' => 'admin',
                        'session_correlation_hash' => $context->sessionCorrelationHash,
                        'target_type' => 'shipping_minimum_setting', 'target_public_id' => $setting->public_id,
                        'before' => $before, 'after' => $this->serialize($setting), 'outcome' => 'success',
                    ]);
                }
                $data = $this->serialize($setting);
                $this->idempotency->complete($claim->record, 'shipping_minimum_setting', $setting->public_id, ['data' => $data]);

                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw new V2PrizeShippingException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED' ? 'IDEMPOTENCY_KEY_REUSED' : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409, 'The setting update conflicts with another request.'
            );
        }
    }

    private function setting(bool $lock = false, bool $exclusive = false): object
    {
        $query = DB::table('shipping_minimum_point_settings')->where('id', 1);
        if ($lock) $query->lock($exclusive ? 'FOR UPDATE' : 'FOR SHARE');
        $row = $query->first();
        if ($row === null || ! Str::isUuid($row->public_id)
            || $this->integer($row->minimum_points, self::MAX_POINTS) === null
            || $this->integer($row->revision, self::MAX_POINTS) === null || (int) $row->revision < 1) {
            throw $this->unavailable();
        }

        return $row;
    }

    private function integer(mixed $value, int $maximum): ?int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/', (string) $value)) return null;
        // At most 18 decimal digits: safe to cast on the required 64-bit PHP runtime.
        if (PHP_INT_SIZE < 8 || (int) $value > $maximum) return null;

        return (int) $value;
    }

    private function serialize(object $setting): array
    {
        return [
            'id' => $setting->public_id, 'minimum_points' => (string) $setting->minimum_points,
            'revision' => (int) $setting->revision,
            'updated_by_admin_id' => $setting->updated_by_admin_id === null ? null
                : DB::table('admins')->where('id', $setting->updated_by_admin_id)->value('public_id'),
            'updated_at' => CarbonImmutable::parse($setting->updated_at)->toIso8601String(),
        ];
    }

    private function unavailable(): V2PrizeShippingException
    {
        return new V2PrizeShippingException('SHIPPING_POLICY_UNAVAILABLE', 503, 'The shipping policy is unavailable.', true);
    }
}

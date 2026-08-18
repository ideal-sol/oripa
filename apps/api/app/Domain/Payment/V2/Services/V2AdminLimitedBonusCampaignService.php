<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Payment\V2\Exceptions\V2PointPurchasePlanException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2AdminLimitedBonusCampaignService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2LimitedBonusCampaignService $campaigns,
        private readonly V2AuditLogService $audit
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listing(V2AdminAuthorizationContext $context, string $planPublicId): array
    {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ReadPointPurchasePlan,
            false,
            'payment.limited_bonus_campaign.read'
        );
        $plan = $this->plan($planPublicId);

        return DB::table('point_purchase_plan_limited_bonus_campaigns')
            ->where('point_purchase_plan_id', $plan->id)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $campaign): array => $this->serialize($campaign, $plan))
            ->values()
            ->all();
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function create(
        V2AdminAuthorizationContext $context,
        string $planPublicId,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManagePointPurchasePlan,
            true,
            'payment.limited_bonus_campaign.create',
            true
        );
        $payload = $this->input($input);

        return $this->mutation(function () use (
            $context,
            $admin,
            $planPublicId,
            $payload,
            $idempotencyKey
        ): array {
            $claim = $this->idempotency->claim(
                'payment.limited_bonus_campaign.create',
                'admin',
                $admin->public_id,
                $idempotencyKey,
                ['plan_id' => $planPublicId, ...$payload]
            );
            if ($claim->replay) {
                return $this->replay($claim->record->response_data);
            }
            $plan = $this->plan($planPublicId);
            $campaign = $this->domainCreate((int) $plan->id, $payload);
            $data = $this->serialize($campaign, $plan);
            $this->recordAudit($context, $admin->public_id, $admin->role->value, null, $data);
            $this->idempotency->complete(
                $claim->record,
                'limited_bonus_campaign',
                $data['id'],
                ['data' => $data]
            );

            return ['data' => $data, 'idempotent_replay' => false];
        });
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function update(
        V2AdminAuthorizationContext $context,
        string $planPublicId,
        string $campaignPublicId,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManagePointPurchasePlan,
            true,
            'payment.limited_bonus_campaign.update',
            true
        );
        $payload = $this->input($input);

        return $this->mutation(function () use (
            $context,
            $admin,
            $planPublicId,
            $campaignPublicId,
            $payload,
            $idempotencyKey
        ): array {
            $claim = $this->idempotency->claim(
                'payment.limited_bonus_campaign.update',
                'admin',
                $admin->public_id,
                $idempotencyKey,
                [
                    'plan_id' => $planPublicId,
                    'campaign_id' => $campaignPublicId,
                    ...$payload,
                ]
            );
            if ($claim->replay) {
                return $this->replay($claim->record->response_data);
            }
            $plan = $this->plan($planPublicId);
            $current = $this->campaign($campaignPublicId, (int) $plan->id);
            $before = $this->serialize($current, $plan);
            $campaign = $this->domainUpdate((int) $current->id, $payload);
            $data = $this->serialize($campaign, $plan);
            $this->recordAudit($context, $admin->public_id, $admin->role->value, $before, $data);
            $this->idempotency->complete(
                $claim->record,
                'limited_bonus_campaign',
                $data['id'],
                ['data' => $data]
            );

            return ['data' => $data, 'idempotent_replay' => false];
        });
    }

    /** @return array{is_enabled: bool, starts_at: string, ends_at: string, bonus_point_amount: int} */
    private function input(array $input): array
    {
        $keys = ['is_enabled', 'starts_at', 'ends_at', 'bonus_point_amount'];
        if (array_diff($keys, array_keys($input)) !== []
            || array_diff(array_keys($input), $keys) !== []
            || ! is_bool($input['is_enabled'] ?? null)
            || ! is_string($input['starts_at'] ?? null)
            || ! is_string($input['ends_at'] ?? null)
            || ! is_int($input['bonus_point_amount'] ?? null)) {
            throw $this->invalid();
        }
        try {
            $start = CarbonImmutable::parse($input['starts_at']);
            $end = CarbonImmutable::parse($input['ends_at']);
        } catch (\Throwable) {
            throw $this->invalid();
        }

        return [
            'is_enabled' => $input['is_enabled'],
            'starts_at' => $start->utc()->toIso8601String(),
            'ends_at' => $end->utc()->toIso8601String(),
            'bonus_point_amount' => $input['bonus_point_amount'],
        ];
    }

    private function domainCreate(int $planId, array $payload): object
    {
        try {
            return $this->campaigns->create(
                $planId,
                $payload['is_enabled'],
                CarbonImmutable::parse($payload['starts_at']),
                CarbonImmutable::parse($payload['ends_at']),
                $payload['bonus_point_amount']
            );
        } catch (V2PaymentException $exception) {
            throw $this->domainError($exception);
        }
    }

    private function domainUpdate(int $campaignId, array $payload): object
    {
        try {
            return $this->campaigns->update(
                $campaignId,
                $payload['is_enabled'],
                CarbonImmutable::parse($payload['starts_at']),
                CarbonImmutable::parse($payload['ends_at']),
                $payload['bonus_point_amount']
            );
        } catch (V2PaymentException $exception) {
            throw $this->domainError($exception);
        }
    }

    private function plan(string $publicId): object
    {
        if (! Str::isUuid($publicId)) {
            throw $this->notFound();
        }
        $plan = DB::table('point_purchase_plans')->where('public_id', $publicId)->first();
        if ($plan === null) {
            throw $this->notFound();
        }

        return $plan;
    }

    private function campaign(string $publicId, int $planId): object
    {
        if (! Str::isUuid($publicId)) {
            throw $this->campaignNotFound();
        }
        $campaign = DB::table('point_purchase_plan_limited_bonus_campaigns')
            ->where('public_id', $publicId)
            ->where('point_purchase_plan_id', $planId)
            ->first();
        if ($campaign === null) {
            throw $this->campaignNotFound();
        }

        return $campaign;
    }

    /** @return array<string, mixed> */
    private function serialize(object $campaign, object $plan): array
    {
        return [
            'id' => (string) $campaign->public_id,
            'point_purchase_plan_id' => (string) $plan->public_id,
            'point_purchase_plan_version' => (int) $plan->version_no,
            'is_enabled' => (bool) $campaign->is_enabled,
            'starts_at' => $this->iso($campaign->starts_at),
            'ends_at' => $this->iso($campaign->ends_at),
            'bonus_point_amount' => (int) $campaign->bonus_point_amount,
            'created_at' => $this->iso($campaign->created_at),
            'updated_at' => $this->iso($campaign->updated_at),
        ];
    }

    private function mutation(callable $operation): array
    {
        try {
            return DB::transaction($operation, 3);
        } catch (V2PointException $exception) {
            throw new V2PointPurchasePlanException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED'
                    ? 'IDEMPOTENCY_KEY_REUSED'
                    : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The limited bonus campaign mutation conflicts with another request.'
            );
        }
    }

    private function replay(mixed $response): array
    {
        if (! is_array($response) || ! isset($response['data']) || ! is_array($response['data'])) {
            throw new V2PointPurchasePlanException(
                'LIMITED_BONUS_CAMPAIGN_UNAVAILABLE',
                503,
                'The limited bonus campaign is unavailable.',
                true
            );
        }

        return ['data' => $response['data'], 'idempotent_replay' => true];
    }

    private function domainError(V2PaymentException $exception): V2PointPurchasePlanException
    {
        return match ($exception->getMessage()) {
            'LIMITED_BONUS_CAMPAIGN_OVERLAP' => new V2PointPurchasePlanException(
                'LIMITED_BONUS_CAMPAIGN_OVERLAP',
                409,
                'The limited bonus campaign overlaps another campaign for this plan version.'
            ),
            default => $this->invalid(),
        };
    }

    private function invalid(): V2PointPurchasePlanException
    {
        return new V2PointPurchasePlanException(
            'LIMITED_BONUS_CAMPAIGN_INVALID',
            422,
            'The limited bonus campaign is invalid.'
        );
    }

    private function notFound(): V2PointPurchasePlanException
    {
        return new V2PointPurchasePlanException(
            'POINT_PURCHASE_PLAN_NOT_FOUND',
            404,
            'The purchase plan was not found.'
        );
    }

    private function campaignNotFound(): V2PointPurchasePlanException
    {
        return new V2PointPurchasePlanException(
            'LIMITED_BONUS_CAMPAIGN_NOT_FOUND',
            404,
            'The limited bonus campaign was not found.'
        );
    }

    private function iso(mixed $value): string
    {
        return CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
    }

    private function recordAudit(
        V2AdminAuthorizationContext $context,
        string $adminPublicId,
        string $role,
        ?array $before,
        array $after
    ): void {
        $this->audit->record(
            $before === null
                ? 'payment.limited_bonus_campaign.created'
                : 'payment.limited_bonus_campaign.updated',
            [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $adminPublicId,
                'actor_role' => $role,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'target_type' => 'limited_bonus_campaign',
                'target_public_id' => $after['id'],
                'before' => $before,
                'after' => $after,
                'outcome' => 'success',
            ]
        );
    }
}

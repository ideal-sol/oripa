<?php

namespace App\Domain\PrizeShipping\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointService;
use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Models\V2\Admin;
use App\Models\V2\PrizeExchangeRequest;
use App\Models\V2\ShippingAddress;
use App\Models\V2\ShippingRequest;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class V2PrizeShippingService
{
    private const USER_PRIZE_TRANSITIONS = [
        'stored' => ['exchange_processing', 'shipping_requested', 'hold', 'expired'],
        'exchange_processing' => ['converted', 'stored'],
        'shipping_requested' => ['packing', 'hold', 'stored'],
        'packing' => ['shipped', 'hold', 'stored'],
        'shipped' => ['delivered', 'return_requested'],
        'hold' => ['stored', 'shipping_requested', 'packing', 'return_requested'],
        'return_requested' => ['returned'],
    ];

    private const SHIPPING_TRANSITIONS = [
        'requested' => ['packing', 'hold', 'canceled'],
        'packing' => ['shipped', 'hold', 'canceled'],
        'shipped' => ['delivered', 'return_requested'],
        'hold' => ['requested', 'packing', 'return_requested'],
        'return_requested' => ['returned'],
    ];

    public function __construct(
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2PointService $points,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    /** @return array<string, mixed> */
    public function prizes(User $user, ?string $cursor, int $limit): array
    {
        $limit = $this->limit($limit);
        $after = $this->decodeCursor($cursor);
        $rows = $this->prizeQuery($user->id)
            ->when($after !== null, fn ($query) => $query->where('up.id', '<', $after))
            ->orderByDesc('up.id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (object $row): array => $this->prize($row))->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $items !== []
                ? $this->encodeCursor((int) $rows->get($limit - 1)->id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function prizeDetail(User $user, string $publicId): array
    {
        $row = $this->prizeQuery($user->id)->where('up.public_id', $publicId)->first();
        if ($row === null) {
            throw $this->notFound('USER_PRIZE_NOT_FOUND');
        }
        $history = DB::table('user_prize_status_histories')
            ->where('user_prize_id', $row->id)
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'reason_code', 'occurred_at'])
            ->map(static fn (object $item): array => [
                'from_status' => $item->from_status,
                'to_status' => $item->to_status,
                'reason_code' => $item->reason_code,
                'occurred_at' => CarbonImmutable::parse($item->occurred_at)->toIso8601String(),
            ])->all();

        return [...$this->prize($row), 'status_history' => $history];
    }

    /**
     * @param list<string> $publicIds
     * @return array<string, mixed>
     */
    public function exchange(
        User $user,
        array $publicIds,
        string $idempotencyKey,
        string $requestId
    ): array {
        $ids = $this->publicIds($publicIds);
        $this->idempotencyKey($idempotencyKey);

        return $this->transactionWithHoldAudit(
            function () use ($user, $ids, $idempotencyKey, $requestId): array {
            $claim = $this->claim('prize.exchange', $user, $idempotencyKey, ['prize_ids' => $ids]);
            if ($claim->replay) {
                $request = PrizeExchangeRequest::query()
                    ->where('public_id', $claim->record->resource_public_id)
                    ->firstOrFail();
                $response = $request->response_data;
                $response['idempotent_replay'] = true;
                $this->audit->record('prize.exchange_replayed', $this->auditAttributes(
                    $user,
                    'prize_exchange_request',
                    $request->public_id,
                    $requestId
                ));

                return $response;
            }

            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            DB::table('wallets')->where('user_id', $user->id)->lockForUpdate()->first();
            $prizes = DB::table('user_prizes')
                ->where('user_id', $user->id)
                ->whereIn('public_id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($prizes->count() !== count($ids)) {
                throw $this->notFound('USER_PRIZE_NOT_FOUND');
            }
            $this->assertNoActiveHold($prizes->pluck('id')->map(fn ($id): int => (int) $id)->all());
            $now = CarbonImmutable::parse(now())->startOfSecond();
            foreach ($prizes as $prize) {
                if (
                    $prize->status !== 'stored'
                    || CarbonImmutable::parse($prize->storage_expires_at)->lessThanOrEqualTo($now)
                    || (int) $prize->exchange_point_snapshot <= 0
                ) {
                    throw new V2PrizeShippingException(
                        'PRIZE_NOT_EXCHANGEABLE',
                        409,
                        'One or more Prizes cannot be exchanged.'
                    );
                }
            }

            $total = (int) $prizes->sum('exchange_point_snapshot');
            $request = new PrizeExchangeRequest();
            $request->forceFill([
                'user_id' => $user->id,
                'idempotency_record_id' => $claim->record->id,
                'request_hash' => $claim->record->request_hash,
                'status' => 'processing',
                'requested_count' => count($ids),
                'exchange_point_total' => 0,
                'created_at' => $now,
            ])->save();

            foreach ($prizes as $prize) {
                $this->changePrizeStatus(
                    $prize,
                    'exchange_processing',
                    'user',
                    $user->public_id,
                    null,
                    'exchange_requested',
                    $requestId,
                    $now
                );
                DB::table('prize_exchange_request_items')->insert([
                    'prize_exchange_request_id' => $request->id,
                    'user_prize_id' => $prize->id,
                    'exchange_point_snapshot' => $prize->exchange_point_snapshot,
                    'created_at' => $now,
                ]);
            }
            $point = $this->points->grantPrizeExchange(
                $user->id,
                $request->id,
                $request->public_id,
                $total,
                $now->copy()->addDays(
                    (int) config('v2_prize_shipping.exchange_point_expiry_days', 180)
                ),
                $now
            );
            foreach ($prizes as $prize) {
                $prize->status = 'exchange_processing';
                $this->changePrizeStatus(
                    $prize,
                    'converted',
                    'user',
                    $user->public_id,
                    null,
                    'exchange_completed',
                    $requestId,
                    $now,
                    ['exchanged_point_amount' => $prize->exchange_point_snapshot, 'terminal_at' => $now]
                );
            }

            $response = [
                'id' => $request->public_id,
                'status' => 'completed',
                'exchanged_count' => count($ids),
                'exchange_point_total' => $total,
                'wallet_free_points_after' => $point['wallet_free_after'],
                'idempotent_replay' => false,
            ];
            DB::table('prize_exchange_requests')->where('id', $request->id)->update([
                'status' => 'completed',
                'exchange_point_total' => $total,
                'point_operation_id' => $point['operation']->id,
                'response_data' => json_encode($response, JSON_THROW_ON_ERROR),
                'completed_at' => $now,
            ]);
            $this->idempotency->complete(
                $claim->record,
                'prize_exchange_request',
                $request->public_id,
                $response
            );
            $this->audit->record('prize.exchanged', $this->auditAttributes(
                $user,
                'prize_exchange_request',
                $request->public_id,
                $requestId,
                ['prize_count' => count($ids), 'point_total' => $total]
            ));
            $this->outbox->enqueue(
                'prize.exchange',
                'prize_exchange_request',
                $request->public_id,
                'prize.exchange.completed',
                ['prize_count' => count($ids), 'point_total' => $total],
                'prize.exchange.completed:'.$request->public_id
            );

                return $response;
            },
            $user,
            'prize.exchange_rejected_hold',
            'prize_selection',
            $ids,
            $requestId
        );
    }

    /** @return array<string, mixed> */
    public function addresses(User $user): array
    {
        $items = ShippingAddress::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShippingAddress $address): array => $this->address($address, false))
            ->all();

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function addressDetail(User $user, string $publicId, string $requestId): array
    {
        $address = $this->ownedAddress($user, $publicId);
        $this->audit->record('shipping.address_read', $this->auditAttributes(
            $user,
            'shipping_address',
            $address->public_id,
            $requestId
        ));

        return $this->address($address, true);
    }

    /** @param array<string, string|null> $input @return array<string, mixed> */
    public function createAddress(User $user, array $input, string $requestId): array
    {
        $plain = $this->validatedAddress($input);

        return $this->transaction(function () use ($user, $plain, $requestId): array {
            $address = new ShippingAddress();
            $address->forceFill([
                'user_id' => $user->id,
                ...$this->encryptedAddress($plain),
            ])->save();
            $this->audit->record('shipping.address_created', $this->auditAttributes(
                $user,
                'shipping_address',
                $address->public_id,
                $requestId
            ));

            return $this->address($address, true);
        });
    }

    /** @param array<string, string|null> $input @return array<string, mixed> */
    public function updateAddress(
        User $user,
        string $publicId,
        array $input,
        string $requestId
    ): array {
        $plain = $this->validatedAddress($input);

        return $this->transaction(function () use (
            $user,
            $publicId,
            $plain,
            $requestId
        ): array {
            $address = $this->ownedAddress($user, $publicId);
            $address->forceFill($this->encryptedAddress($plain))->save();
            $this->audit->record('shipping.address_updated', $this->auditAttributes(
                $user,
                'shipping_address',
                $address->public_id,
                $requestId
            ));

            return $this->address($address->refresh(), true);
        });
    }

    public function deleteAddress(User $user, string $publicId, string $requestId): void
    {
        $this->transaction(function () use ($user, $publicId, $requestId): void {
            $address = $this->ownedAddress($user, $publicId);
            $address->delete();
            $this->audit->record('shipping.address_deleted', $this->auditAttributes(
                $user,
                'shipping_address',
                $address->public_id,
                $requestId
            ));
        });
    }

    /**
     * @param list<string> $prizeIds
     * @return array<string, mixed>
     */
    public function createShippingRequest(
        User $user,
        string $addressId,
        array $prizeIds,
        string $idempotencyKey,
        string $requestId
    ): array {
        $ids = $this->publicIds($prizeIds);
        $this->idempotencyKey($idempotencyKey);

        return $this->transactionWithHoldAudit(
            function () use ($user, $addressId, $ids, $idempotencyKey, $requestId): array {
            $claim = $this->claim('shipping.request', $user, $idempotencyKey, [
                'address_id' => $addressId,
                'prize_ids' => $ids,
            ]);
            if ($claim->replay) {
                $request = ShippingRequest::query()
                    ->where('public_id', $claim->record->resource_public_id)
                    ->firstOrFail();
                $response = $request->response_data;
                $response['idempotent_replay'] = true;

                return $response;
            }

            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            DB::table('wallets')->where('user_id', $user->id)->lockForUpdate()->first();
            $prizes = DB::table('user_prizes')
                ->where('user_id', $user->id)
                ->whereIn('public_id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($prizes->count() !== count($ids)) {
                throw $this->notFound('USER_PRIZE_NOT_FOUND');
            }
            $this->assertNoActiveHold($prizes->pluck('id')->map(fn ($id): int => (int) $id)->all());
            $now = CarbonImmutable::parse(now())->startOfSecond();
            foreach ($prizes as $prize) {
                if (
                    $prize->status !== 'stored'
                    || CarbonImmutable::parse($prize->storage_expires_at)->lessThanOrEqualTo($now)
                ) {
                    throw new V2PrizeShippingException(
                        'PRIZE_NOT_SHIPPABLE',
                        409,
                        'One or more Prizes cannot be shipped.'
                    );
                }
            }
            $address = $this->ownedAddress($user, $addressId);
            $plain = $this->decryptedAddress($address);
            $shipping = new ShippingRequest();
            $shipping->forceFill([
                'user_id' => $user->id,
                'shipping_address_id' => $address->id,
                'idempotency_record_id' => $claim->record->id,
                'request_hash' => $claim->record->request_hash,
                'status' => 'requested',
                ...$this->encryptedAddress($plain, snapshot: true),
                'requested_at' => $now,
            ])->save();
            foreach ($prizes as $prize) {
                $this->changePrizeStatus(
                    $prize,
                    'shipping_requested',
                    'user',
                    $user->public_id,
                    null,
                    'shipping_requested',
                    $requestId,
                    $now
                );
                DB::table('shipping_request_items')->insert([
                    'shipping_request_id' => $shipping->id,
                    'user_prize_id' => $prize->id,
                    'created_at' => $now,
                ]);
            }
            $this->shippingHistory(
                $shipping,
                null,
                'requested',
                'user',
                $user->public_id,
                null,
                'shipping_requested',
                $requestId,
                $now
            );
            $response = [
                'id' => $shipping->public_id,
                'status' => 'requested',
                'prize_count' => count($ids),
                'requested_at' => $now->toIso8601String(),
                'idempotent_replay' => false,
            ];
            DB::table('shipping_requests')->where('id', $shipping->id)->update([
                'response_data' => json_encode($response, JSON_THROW_ON_ERROR),
            ]);
            $this->idempotency->complete(
                $claim->record,
                'shipping_request',
                $shipping->public_id,
                $response
            );
            $this->audit->record('shipping.request_created', $this->auditAttributes(
                $user,
                'shipping_request',
                $shipping->public_id,
                $requestId,
                ['prize_count' => count($ids)]
            ));
            $this->outbox->enqueue(
                'shipping.request',
                'shipping_request',
                $shipping->public_id,
                'shipping.request.created',
                ['prize_count' => count($ids)],
                'shipping.request.created:'.$shipping->public_id
            );

                return $response;
            },
            $user,
            'shipping.request_rejected_hold',
            'prize_selection',
            $ids,
            $requestId
        );
    }

    /** @return array<string, mixed> */
    public function shippingRequests(User $user, ?string $cursor, int $limit): array
    {
        return $this->shippingPage(
            DB::table('shipping_requests')->where('user_id', $user->id),
            $cursor,
            $limit,
            false
        );
    }

    /** @return array<string, mixed> */
    public function shippingDetail(User $user, string $publicId, string $requestId): array
    {
        $request = ShippingRequest::query()
            ->where('user_id', $user->id)
            ->where('public_id', $publicId)
            ->first();
        if ($request === null) {
            throw $this->notFound('SHIPPING_REQUEST_NOT_FOUND');
        }
        $this->audit->record('shipping.request_address_read', $this->auditAttributes(
            $user,
            'shipping_request',
            $request->public_id,
            $requestId
        ));

        return $this->shipping($request, true, false);
    }

    /** @return array<string, mixed> */
    public function adminShippingRequests(?string $cursor, int $limit): array
    {
        return $this->shippingPage(DB::table('shipping_requests'), $cursor, $limit, true);
    }

    /** @return array<string, mixed> */
    public function adminShippingDetail(Admin $admin, string $publicId, string $requestId): array
    {
        $request = ShippingRequest::query()->where('public_id', $publicId)->first();
        if ($request === null) {
            throw $this->notFound('SHIPPING_REQUEST_NOT_FOUND');
        }
        $this->audit->record('shipping.admin_address_read', $this->auditAttributes(
            $admin,
            'shipping_request',
            $request->public_id,
            $requestId,
            permission: 'shipping.request.manage'
        ));

        return $this->shipping($request, true, true);
    }

    /** @return array<string, mixed> */
    public function transitionShipping(
        Admin $admin,
        string $publicId,
        string $toStatus,
        ?string $carrierCode,
        ?string $trackingNumber,
        ?string $reason,
        string $requestId
    ): array {
        return $this->transaction(function () use (
            $admin,
            $publicId,
            $toStatus,
            $carrierCode,
            $trackingNumber,
            $reason,
            $requestId
        ): array {
            $shipping = ShippingRequest::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();
            if ($shipping === null) {
                throw $this->notFound('SHIPPING_REQUEST_NOT_FOUND');
            }
            $from = $shipping->status;
            if (! in_array($toStatus, self::SHIPPING_TRANSITIONS[$from] ?? [], true)) {
                throw new V2PrizeShippingException(
                    'SHIPPING_TRANSITION_NOT_ALLOWED',
                    409,
                    'The Shipping transition is not allowed.'
                );
            }
            if (
                in_array($toStatus, ['shipped', 'delivered', 'returned'], true)
                && (
                    (! is_string($carrierCode) || trim($carrierCode) === '')
                        && ($shipping->carrier_code === null || $shipping->carrier_code === '')
                    || (! is_string($trackingNumber) || trim($trackingNumber) === '')
                        && $shipping->tracking_number_ciphertext === null
                )
            ) {
                throw new V2PrizeShippingException(
                    'TRACKING_REQUIRED',
                    422,
                    'Carrier and Tracking Number are required.'
                );
            }
            $now = CarbonImmutable::parse(now())->startOfSecond();
            $items = DB::table('shipping_request_items as item')
                ->join('user_prizes as prize', 'prize.id', '=', 'item.user_prize_id')
                ->where('item.shipping_request_id', $shipping->id)
                ->orderBy('prize.id')
                ->lockForUpdate()
                ->get(['prize.*']);
            $targetPrizeStatus = $this->prizeStatusForShipping($toStatus);
            foreach ($items as $prize) {
                $this->changePrizeStatus(
                    $prize,
                    $targetPrizeStatus,
                    'admin',
                    $admin->public_id,
                    $admin->role->value,
                    $reason ?: 'shipping_status_updated',
                    $requestId,
                    $now,
                    in_array($targetPrizeStatus, ['delivered', 'returned', 'canceled'], true)
                        ? ['terminal_at' => $now]
                        : []
                );
            }
            $trackingCiphertext = $trackingNumber === null
                ? $shipping->tracking_number_ciphertext
                : Crypt::encryptString(trim($trackingNumber));
            $shipping->forceFill([
                'status' => $toStatus,
                'carrier_code' => $carrierCode === null ? $shipping->carrier_code : trim($carrierCode),
                'tracking_number_ciphertext' => $trackingCiphertext,
                'shipped_at' => $toStatus === 'shipped' ? $now : $shipping->shipped_at,
                'terminal_at' => in_array($toStatus, ['delivered', 'returned', 'canceled'], true)
                    ? $now
                    : null,
            ])->save();
            $this->shippingHistory(
                $shipping,
                $from,
                $toStatus,
                'admin',
                $admin->public_id,
                $admin->role->value,
                $reason ?: 'shipping_status_updated',
                $requestId,
                $now,
                $trackingNumber
            );
            $this->audit->record('shipping.status_changed', $this->auditAttributes(
                $admin,
                'shipping_request',
                $shipping->public_id,
                $requestId,
                ['from_status' => $from, 'to_status' => $toStatus],
                'shipping.request.manage'
            ));
            $this->outbox->enqueue(
                'shipping.status',
                'shipping_request',
                $shipping->public_id,
                'shipping.status.changed',
                ['from_status' => $from, 'to_status' => $toStatus],
                'shipping.status.changed:'.$shipping->public_id.':'.$toStatus
            );

            return $this->shipping($shipping->refresh(), true, true);
        });
    }

    private function prizeQuery(int $userId): \Illuminate\Database\Query\Builder
    {
        return DB::table('user_prizes as up')
            ->join('draw_results as dr', 'dr.id', '=', 'up.draw_result_id')
            ->where('up.user_id', $userId)
            ->select([
                'up.id',
                'up.public_id',
                'up.status',
                'up.exchange_point_snapshot',
                'up.exchanged_point_amount',
                'up.acquired_at',
                'up.storage_expires_at',
                'up.terminal_at',
                'dr.public_id as draw_result_public_id',
                'dr.display_snapshot',
                'dr.display_snapshot_sha256',
            ]);
    }

    /** @return array<string, mixed> */
    private function prize(object $row): array
    {
        $snapshot = is_string($row->display_snapshot)
            ? json_decode($row->display_snapshot, true, flags: JSON_THROW_ON_ERROR)
            : (array) $row->display_snapshot;

        return [
            'id' => $row->public_id,
            'status' => $row->status,
            'exchange_points' => (int) $row->exchange_point_snapshot,
            'acquired_at' => CarbonImmutable::parse($row->acquired_at)->toIso8601String(),
            'storage_expires_at' => CarbonImmutable::parse(
                $row->storage_expires_at
            )->toIso8601String(),
            'draw_result_id' => $row->draw_result_public_id,
            'display' => $snapshot['prize'] ?? null,
            'rank' => $snapshot['rank'] ?? null,
        ];
    }

    private function ownedAddress(User $user, string $publicId): ShippingAddress
    {
        $address = ShippingAddress::query()
            ->where('user_id', $user->id)
            ->where('public_id', $publicId)
            ->first();
        if ($address === null) {
            throw $this->notFound('SHIPPING_ADDRESS_NOT_FOUND');
        }

        return $address;
    }

    /** @param array<string, string|null> $input @return array<string, string|null> */
    private function validatedAddress(array $input): array
    {
        $limits = [
            'recipient_name' => 120,
            'postal_code' => 16,
            'prefecture' => 32,
            'city' => 120,
            'street' => 191,
            'building' => 191,
            'phone_number' => 32,
        ];
        $result = [];
        foreach ($limits as $field => $maximum) {
            $value = $input[$field] ?? null;
            if ($field === 'building' && ($value === null || trim((string) $value) === '')) {
                $result[$field] = null;
                continue;
            }
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum) {
                throw new V2PrizeShippingException(
                    'INVALID_SHIPPING_ADDRESS',
                    422,
                    'The Shipping Address is invalid.'
                );
            }
            $result[$field] = trim($value);
        }

        return $result;
    }

    /** @param array<string, string|null> $plain @return array<string, string|null> */
    private function encryptedAddress(array $plain, bool $snapshot = false): array
    {
        $suffix = $snapshot ? '_ciphertext' : '_ciphertext';
        $result = [];
        foreach ([
            'recipient_name',
            'postal_code',
            'prefecture',
            'city',
            'street',
            'building',
            'phone_number',
        ] as $field) {
            $result[$field.$suffix] = $plain[$field] === null
                ? null
                : Crypt::encryptString($plain[$field]);
        }
        $canonical = implode("\n", array_map(
            static fn ($value): string => $value ?? '',
            array_values($plain)
        ));
        $result[$snapshot ? 'address_snapshot_hash' : 'correlation_hash'] = hash_hmac(
            'sha256',
            $canonical,
            $this->piiCorrelationKey()
        );

        return $result;
    }

    /** @return array<string, string|null> */
    private function decryptedAddress(object $address): array
    {
        $result = [];
        foreach ([
            'recipient_name',
            'postal_code',
            'prefecture',
            'city',
            'street',
            'building',
            'phone_number',
        ] as $field) {
            $ciphertext = $address->{$field.'_ciphertext'};
            $result[$field] = $ciphertext === null ? null : Crypt::decryptString($ciphertext);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function address(ShippingAddress $address, bool $full): array
    {
        $plain = $this->decryptedAddress($address);
        if (! $full) {
            return [
                'id' => $address->public_id,
                'recipient_name_masked' => $this->mask($plain['recipient_name']),
                'postal_code_masked' => $this->mask($plain['postal_code']),
                'phone_number_masked' => $this->mask($plain['phone_number']),
                'updated_at' => $address->updated_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $address->public_id,
            ...$plain,
            'created_at' => $address->created_at?->toIso8601String(),
            'updated_at' => $address->updated_at?->toIso8601String(),
        ];
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return mb_substr($value, 0, 1).str_repeat('*', min(7, max(2, mb_strlen($value) - 1)));
    }

    /** @return array<string, mixed> */
    private function shippingPage(
        \Illuminate\Database\Query\Builder $query,
        ?string $cursor,
        int $limit,
        bool $admin
    ): array {
        $limit = $this->limit($limit);
        $after = $this->decodeCursor($cursor);
        $rows = $query
            ->when($after !== null, fn ($builder) => $builder->where('id', '<', $after))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $pageRows = $rows->take($limit);
        $counts = DB::table('shipping_request_items')
            ->whereIn('shipping_request_id', $pageRows->pluck('id')->all())
            ->selectRaw('shipping_request_id, COUNT(*) AS prize_count')
            ->groupBy('shipping_request_id')
            ->pluck('prize_count', 'shipping_request_id');
        $items = $pageRows->map(
            fn (object $row): array => $this->shippingSummary(
                $row,
                (int) ($counts[$row->id] ?? 0)
            )
        )->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $items !== []
                ? $this->encodeCursor((int) $rows->get($limit - 1)->id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function shippingSummary(object $request, int $prizeCount): array
    {
        return [
            'id' => $request->public_id,
            'status' => $request->status,
            'prize_count' => $prizeCount,
            'requested_at' => $request->requested_at === null
                ? null
                : CarbonImmutable::parse($request->requested_at)->toIso8601String(),
            'shipped_at' => $request->shipped_at === null
                ? null
                : CarbonImmutable::parse($request->shipped_at)->toIso8601String(),
            'carrier_code' => $request->carrier_code,
        ];
    }

    /** @return array<string, mixed> */
    private function shipping(ShippingRequest $request, bool $detail, bool $admin): array
    {
        $prizeIds = DB::table('shipping_request_items as item')
            ->join('user_prizes as prize', 'prize.id', '=', 'item.user_prize_id')
            ->where('item.shipping_request_id', $request->id)
            ->orderBy('item.id')
            ->pluck('prize.public_id')
            ->all();
        $result = [
            'id' => $request->public_id,
            'status' => $request->status,
            'prize_count' => count($prizeIds),
            'requested_at' => $request->requested_at?->toIso8601String(),
            'shipped_at' => $request->shipped_at?->toIso8601String(),
            'carrier_code' => $request->carrier_code,
        ];
        if ($detail) {
            $result['prize_ids'] = $prizeIds;
            $result['tracking_number'] = $request->tracking_number_ciphertext === null
                ? null
                : Crypt::decryptString($request->tracking_number_ciphertext);
            $result['shipping_address'] = $this->decryptedAddress($request);
            $result['status_history'] = DB::table('shipping_request_status_histories')
                ->where('shipping_request_id', $request->id)
                ->orderBy('id')
                ->get(['from_status', 'to_status', 'reason_code', 'occurred_at'])
                ->map(static fn (object $history): array => [
                    'from_status' => $history->from_status,
                    'to_status' => $history->to_status,
                    'reason_code' => $history->reason_code,
                    'occurred_at' => CarbonImmutable::parse(
                        $history->occurred_at
                    )->toIso8601String(),
                ])->all();
        }

        return $result;
    }

    private function changePrizeStatus(
        object $prize,
        string $to,
        string $actorType,
        string $actorPublicId,
        ?string $actorRole,
        string $reason,
        string $requestId,
        CarbonImmutable $occurredAt,
        array $extra = []
    ): void {
        $from = $prize->status;
        if (! in_array($to, self::USER_PRIZE_TRANSITIONS[$from] ?? [], true)) {
            throw new V2PrizeShippingException(
                'PRIZE_TRANSITION_NOT_ALLOWED',
                409,
                'The User Prize transition is not allowed.'
            );
        }
        DB::table('user_prizes')->where('id', $prize->id)->update([
            'status' => $to,
            'updated_at' => $occurredAt,
            ...$extra,
        ]);
        DB::table('user_prize_status_histories')->insert([
            'user_prize_id' => $prize->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_public_id' => $actorPublicId,
            'actor_role' => $actorRole,
            'reason_code' => $this->reason($reason),
            'request_id' => $requestId,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function shippingHistory(
        ShippingRequest $shipping,
        ?string $from,
        string $to,
        string $actorType,
        string $actorPublicId,
        ?string $actorRole,
        string $reason,
        string $requestId,
        CarbonImmutable $occurredAt,
        ?string $trackingNumber = null
    ): void {
        DB::table('shipping_request_status_histories')->insert([
            'shipping_request_id' => $shipping->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_public_id' => $actorPublicId,
            'actor_role' => $actorRole,
            'reason_code' => $this->reason($reason),
            'carrier_code' => $shipping->carrier_code,
            'tracking_correlation_hash' => $trackingNumber === null
                ? null
                : hash_hmac('sha256', $trackingNumber, $this->piiCorrelationKey()),
            'shipped_at' => $shipping->shipped_at,
            'request_id' => $requestId,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function assertNoActiveHold(array $prizeIds): void
    {
        $hold = DB::table('payment_adjustment_prize_actions')
            ->whereIn('user_prize_id', $prizeIds)
            ->whereIn('action_type', ['hold', 'return_request'])
            ->whereIn('status', ['pending', 'completed', 'manual_review'])
            ->exists();
        if ($hold) {
            throw new V2PrizeShippingException(
                'PRIZE_ON_PAYMENT_HOLD',
                409,
                'The Prize is held by a Payment Adjustment.'
            );
        }
    }

    private function prizeStatusForShipping(string $shippingStatus): string
    {
        return match ($shippingStatus) {
            'requested' => 'shipping_requested',
            'packing' => 'packing',
            'shipped' => 'shipped',
            'delivered' => 'delivered',
            'hold' => 'hold',
            'return_requested' => 'return_requested',
            'returned' => 'returned',
            'canceled' => 'stored',
            default => throw new V2PrizeShippingException(
                'SHIPPING_TRANSITION_NOT_ALLOWED',
                409,
                'The Shipping transition is not allowed.'
            ),
        };
    }

    private function claim(string $scope, User $user, string $key, array $request): object
    {
        try {
            return $this->idempotency->claim($scope, 'user', $user->public_id, $key, $request);
        } catch (V2PointException $exception) {
            throw match ($exception->getMessage()) {
                'IDEMPOTENCY_KEY_REUSED' => new V2PrizeShippingException(
                    'IDEMPOTENCY_KEY_REUSED',
                    409,
                    'The Idempotency Key was used for a different request.'
                ),
                'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2PrizeShippingException(
                    'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                    409,
                    'The request is still processing.',
                    true
                ),
                default => new V2PrizeShippingException(
                    'IDEMPOTENCY_FAILURE',
                    422,
                    'The Idempotency boundary rejected the request.'
                ),
            };
        }
    }

    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (V2PrizeShippingException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['40001', '40P01'], true)) {
                throw new V2PrizeShippingException(
                    'CONCURRENT_OPERATION_RETRY_EXHAUSTED',
                    409,
                    'The concurrent operation could not be completed.',
                    true
                );
            }
            throw $exception;
        }
    }

    /** @param list<string> $targetPublicIds */
    private function transactionWithHoldAudit(
        callable $callback,
        User $user,
        string $action,
        string $targetType,
        array $targetPublicIds,
        string $requestId
    ): mixed {
        try {
            return $this->transaction($callback);
        } catch (V2PrizeShippingException $exception) {
            if ($exception->errorCode === 'PRIZE_ON_PAYMENT_HOLD') {
                $this->audit->record($action, $this->auditAttributes(
                    $user,
                    $targetType,
                    null,
                    $requestId,
                    [
                        'selection_correlation_hash' => hash(
                            'sha256',
                            implode("\n", $targetPublicIds)
                        ),
                    ]
                ) + [
                    'outcome' => 'failure',
                    'reason_code' => strtolower($exception->errorCode),
                ]);
            }

            throw $exception;
        }
    }

    /** @param list<string> $ids @return list<string> */
    private function publicIds(array $ids): array
    {
        if ($ids === [] || count($ids) > 100) {
            throw new V2PrizeShippingException(
                'INVALID_PRIZE_SELECTION',
                422,
                'The Prize selection is invalid.'
            );
        }
        $result = array_values(array_unique($ids));
        sort($result, SORT_STRING);
        $invalid = false;
        foreach ($result as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                $invalid = true;
                break;
            }
        }
        if (count($result) !== count($ids) || $invalid) {
            throw new V2PrizeShippingException(
                'INVALID_PRIZE_SELECTION',
                422,
                'The Prize selection is invalid.'
            );
        }

        return $result;
    }

    private function idempotencyKey(string $key): void
    {
        $length = strlen($key);
        if (
            $length < (int) config('v2_prize_shipping.idempotency_key_minimum', 16)
            || $length > (int) config('v2_prize_shipping.idempotency_key_maximum', 128)
        ) {
            throw new V2PrizeShippingException(
                'INVALID_IDEMPOTENCY_KEY',
                422,
                'The Idempotency Key is invalid.'
            );
        }
    }

    private function limit(int $limit): int
    {
        $maximum = (int) config('v2_prize_shipping.cursor_page_size_maximum', 100);
        if ($limit < 1 || $limit > $maximum) {
            throw new V2PrizeShippingException(
                'INVALID_PAGINATION',
                422,
                'The pagination input is invalid.'
            );
        }

        return $limit;
    }

    private function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode((string) $id), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || ! ctype_digit($decoded) || (int) $decoded < 1) {
            throw new V2PrizeShippingException(
                'INVALID_CURSOR',
                422,
                'The cursor is invalid.'
            );
        }

        return (int) $decoded;
    }

    private function piiCorrelationKey(): string
    {
        $key = config('v2_prize_shipping.address_hmac_key');
        if (! is_string($key) || $key === '') {
            throw new V2PrizeShippingException(
                'PII_PROTECTION_UNAVAILABLE',
                503,
                'PII protection is unavailable.'
            );
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false || strlen($decoded) < 32) {
                throw new V2PrizeShippingException(
                    'PII_PROTECTION_UNAVAILABLE',
                    503,
                    'PII protection is unavailable.'
                );
            }

            return $decoded;
        }
        if (strlen($key) < 32) {
            throw new V2PrizeShippingException(
                'PII_PROTECTION_UNAVAILABLE',
                503,
                'PII protection is unavailable.'
            );
        }

        return $key;
    }

    private function reason(string $reason): string
    {
        $value = Str::snake(trim($reason));

        return preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $value)
            ? $value
            : 'shipping_status_updated';
    }

    private function notFound(string $code): V2PrizeShippingException
    {
        return new V2PrizeShippingException($code, 404, 'The requested resource was not found.');
    }

    /** @return array<string, mixed> */
    private function auditAttributes(
        User|Admin $actor,
        string $targetType,
        ?string $targetPublicId,
        string $requestId,
        array $metadata = [],
        ?string $permission = null
    ): array {
        $admin = $actor instanceof Admin;

        return [
            'actor_type' => $admin ? 'admin' : 'user',
            'actor_public_id' => $actor->public_id,
            'actor_role' => $admin ? $actor->role->value : null,
            'auth_realm' => $admin ? 'admin' : 'user',
            'target_type' => $targetType,
            'target_public_id' => $targetPublicId,
            'request_id' => $requestId,
            'metadata' => [
                ...$metadata,
                ...($permission === null ? [] : ['permission' => $permission]),
            ],
        ];
    }
}

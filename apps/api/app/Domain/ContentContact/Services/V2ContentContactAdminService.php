<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\ValueObjects\V2IdempotencyClaim;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2ContentContactAdminService
{
    private const TYPES = [
        'banner' => ['content_banners', 'banner_id', 'code'],
        'notice' => ['content_notices', 'notice_id', 'slug'],
        'static-page' => ['content_static_pages', 'static_page_id', 'slug'],
    ];

    private const CONTACT_TRANSITIONS = [
        'new' => ['in_progress', 'replied', 'closed'],
        'in_progress' => ['replied', 'closed'],
        'replied' => ['closed'],
        'closed' => [],
    ];

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
        private readonly V2ContentCursor $cursor,
        private readonly V2ContentHtmlSanitizer $sanitizer,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    /** @return array<string, mixed> */
    public function contentList(
        V2AdminAuthorizationContext $context,
        string $type,
        ?string $cursor,
        int $limit
    ): array {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContent);
        [$table, $ownerColumn] = $this->type($type);
        $after = $this->cursor->decode($cursor);
        $limit = $this->limit($limit);
        $latestVersions = DB::table('content_versions')
            ->select($ownerColumn)
            ->selectRaw('MAX(version_number) AS latest_version_number')
            ->whereNotNull($ownerColumn)
            ->groupBy($ownerColumn);
        $rows = DB::table($table.' as p')
            ->leftJoin(
                'content_versions as published',
                'published.id',
                '=',
                'p.published_version_id'
            )
            ->leftJoinSub($latestVersions, 'latest_numbers', function ($join) use ($ownerColumn): void {
                $join->on('latest_numbers.'.$ownerColumn, '=', 'p.id');
            })
            ->leftJoin('content_versions as latest', function ($join) use ($ownerColumn): void {
                $join->on('latest.'.$ownerColumn, '=', 'p.id')
                    ->on('latest.version_number', '=', 'latest_numbers.latest_version_number');
            })
            ->select([
                'p.*',
                'published.public_id as published_version_public_id',
                'latest.public_id as latest_version_public_id',
                'latest.version_number as latest_version_number',
                'latest.status as latest_version_status',
                'latest.title as latest_title',
                'latest.summary as latest_summary',
                'latest.body_html as latest_body_html',
                'latest.link_url as latest_link_url',
                'latest.sort_order as latest_sort_order',
                'latest.is_important as latest_is_important',
                'latest.publish_start_at as latest_publish_start_at',
                'latest.publish_end_at as latest_publish_end_at',
                'latest.content_checksum as latest_content_checksum',
                'latest.published_at as latest_published_at',
            ])
            ->selectSub(
                DB::table('content_version_assets as latest_asset_link')
                    ->join(
                        'catalog_presentation_assets as latest_asset',
                        'latest_asset.id',
                        '=',
                        'latest_asset_link.presentation_asset_id'
                    )
                    ->select('latest_asset.public_id')
                    ->whereColumn('latest_asset_link.content_version_id', 'latest.id')
                    ->whereIn('latest_asset_link.usage_type', ['image', 'thumbnail'])
                    ->orderBy('latest_asset_link.sort_order')
                    ->orderBy('latest_asset_link.id')
                    ->limit(1),
                'latest_asset_public_id'
            )
            ->when(
                $after !== null,
                fn (Builder $query) => $query->where('p.id', '<', $after)
            )
            ->orderByDesc('p.id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (object $row): array => [
            'id' => $row->public_id,
            'identifier' => $row->code ?? $row->slug,
            'status' => $row->status,
            'is_legal' => property_exists($row, 'is_legal') ? (bool) $row->is_legal : false,
            'published_version_id' => $row->published_version_public_id,
            'latest_version' => $this->latestVersion($row),
            'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            'updated_at' => CarbonImmutable::parse($row->updated_at)->toIso8601String(),
        ])->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore
                ? $this->cursor->encode((int) $rows->get($limit - 1)->id)
                : null,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createContent(
        V2AdminAuthorizationContext $context,
        string $type,
        array $input,
        string $idempotencyKey = ''
    ): array {
        $admin = $this->authorizer->authorizePermission(
            $context,
            V2Permission::ManageContent
        );
        [$table, $ownerColumn, $identifierColumn] = $this->type($type);
        $identifier = $this->identifier($input[$identifierColumn] ?? null);
        $isLegal = $type === 'static-page' && in_array(
            $identifier,
            (array) config('v2_content_contact.legal_slugs', []),
            true
        );

        return DB::transaction(function () use (
            $context,
            $type,
            $table,
            $ownerColumn,
            $identifierColumn,
            $identifier,
            $isLegal,
            $input,
            $admin,
            $idempotencyKey
        ): array {
            $claim = null;
            if ($type === 'notice' && $idempotencyKey !== '') {
                $claim = $this->claimNoticeMutation(
                    $admin,
                    $idempotencyKey,
                    ['action' => 'create', 'input' => $input]
                );
                if ($claim->replay) {
                    $data = $claim->record->response_data['data'] ?? null;
                    if (! is_array($data)) {
                        throw new \RuntimeException('Notice replay response is unavailable.');
                    }

                    return [...$data, 'idempotent_replay' => true];
                }
            }
            $publicId = (string) Str::uuid7();
            $now = now()->startOfSecond();
            $parent = [
                'public_id' => $publicId,
                $identifierColumn => $identifier,
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($type === 'static-page') {
                $parent['is_legal'] = $isLegal;
            }
            try {
                $parentId = DB::table($table)->insertGetId($parent);
            } catch (\Throwable) {
                throw $this->conflict('CONTENT_IDENTIFIER_CONFLICT');
            }
            $version = $this->createVersionRow(
                $type,
                $ownerColumn,
                $parentId,
                1,
                $input,
                $admin
            );
            $this->auditContent(
                'content.created',
                $context,
                $type,
                $publicId,
                ['version_public_id' => $version['id']]
            );

            $result = [
                'id' => $publicId,
                'identifier' => $identifier,
                'status' => 'draft',
                'is_legal' => $isLegal,
                'versions' => [$version],
            ];
            if ($claim !== null) {
                $this->idempotency->complete(
                    $claim->record,
                    'content_notice',
                    $publicId,
                    ['data' => $result]
                );
                DB::table('idempotency_records')->where('id', $claim->record->id)
                    ->update(['response_status' => 201]);
                $result['idempotent_replay'] = false;
            }

            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function contentDetail(
        V2AdminAuthorizationContext $context,
        string $type,
        string $publicId
    ): array {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContent);
        [$table, $ownerColumn, $identifierColumn] = $this->type($type);
        $parent = DB::table($table)->where('public_id', $publicId)->first();
        if ($parent === null) {
            throw $this->notFound();
        }

        return [
            'id' => $parent->public_id,
            'identifier' => $parent->{$identifierColumn},
            'status' => $parent->status,
            'is_legal' => property_exists($parent, 'is_legal') ? (bool) $parent->is_legal : false,
            'versions' => DB::table('content_versions')
                ->where($ownerColumn, $parent->id)
                ->select('content_versions.*')
                ->selectSub(
                    DB::table('content_version_assets as cva')
                        ->join(
                            'catalog_presentation_assets as a',
                            'a.id',
                            '=',
                            'cva.presentation_asset_id'
                        )
                        ->select('a.public_id')
                        ->whereColumn(
                            'cva.content_version_id',
                            'content_versions.id'
                        )
                        ->orderBy('cva.sort_order')
                        ->orderBy('cva.id')
                        ->limit(1),
                    'asset_public_id'
                )
                ->orderByDesc('version_number')
                ->get()
                ->map(fn (object $row): array => $this->version($row, true))
                ->all(),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createVersion(
        V2AdminAuthorizationContext $context,
        string $type,
        string $publicId,
        array $input,
        string $idempotencyKey = ''
    ): array {
        $admin = $this->authorizer->authorizePermission(
            $context,
            V2Permission::ManageContent
        );
        [$table, $ownerColumn] = $this->type($type);

        return DB::transaction(function () use (
            $context,
            $type,
            $publicId,
            $input,
            $admin,
            $table,
            $ownerColumn,
            $idempotencyKey
        ): array {
            $claim = null;
            if ($type === 'notice' && $idempotencyKey !== '') {
                $claim = $this->claimNoticeMutation(
                    $admin,
                    $idempotencyKey,
                    ['action' => 'update', 'content_id' => $publicId, 'input' => $input]
                );
                if ($claim->replay) {
                    $data = $claim->record->response_data['data'] ?? null;
                    if (! is_array($data)) {
                        throw new \RuntimeException('Notice replay response is unavailable.');
                    }

                    return [...$data, 'idempotent_replay' => true];
                }
            }
            $parent = DB::table($table)->where('public_id', $publicId)
                ->lockForUpdate()->first();
            if ($parent === null) {
                throw $this->notFound();
            }
            if ($parent->status === 'archived') {
                throw $this->conflict('CONTENT_ARCHIVED');
            }
            $number = (int) DB::table('content_versions')
                ->where($ownerColumn, $parent->id)->max('version_number') + 1;
            $version = $this->createVersionRow(
                $type,
                $ownerColumn,
                (int) $parent->id,
                $number,
                $input,
                $admin
            );
            $this->auditContent(
                'content.version_created',
                $context,
                $type,
                $publicId,
                ['version_public_id' => $version['id'], 'version_number' => $number]
            );

            if ($claim !== null) {
                $this->idempotency->complete(
                    $claim->record,
                    'content_version',
                    $version['id'],
                    ['data' => $version]
                );
                DB::table('idempotency_records')->where('id', $claim->record->id)
                    ->update(['response_status' => 201]);
                $version['idempotent_replay'] = false;
            }

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function previewNotice(
        V2AdminAuthorizationContext $context,
        array $input
    ): array {
        $this->authorizer->authorizePermission($context, V2Permission::ManageContent);
        $start = $this->date($input['publish_start_at'] ?? null);
        $end = ($input['publish_end_at'] ?? null) === null
            ? null
            : $this->date($input['publish_end_at']);
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw $this->invalid('CONTENT_PUBLISH_PERIOD_INVALID');
        }
        try {
            $body = $this->sanitizer->sanitize(
                $this->string($input['body_html'] ?? null, 1, 100_000)
            );
        } catch (\RuntimeException) {
            throw $this->invalid('CONTENT_BODY_INVALID');
        }

        return [
            'title' => $this->string($input['title'] ?? null, 1, 191),
            'summary' => $this->nullableString($input['summary'] ?? null, 500),
            'body_html' => $body,
            'is_important' => ($input['is_important'] ?? false) === true,
            'asset_id' => $input['asset_id'] ?? null,
            'publish_start_at' => $start->utc()->toIso8601String(),
            'publish_end_at' => $end?->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function publish(
        V2AdminAuthorizationContext $context,
        string $type,
        string $publicId,
        string $versionPublicId
    ): array {
        $this->authorizer->authorizePermission($context, V2Permission::PublishContent);
        [$table, $ownerColumn] = $this->type($type);

        return DB::transaction(function () use (
            $context,
            $type,
            $publicId,
            $versionPublicId,
            $table,
            $ownerColumn
        ): array {
            $parent = DB::table($table)->where('public_id', $publicId)
                ->lockForUpdate()->first();
            if ($parent === null) {
                throw $this->notFound();
            }
            $isLegal = property_exists($parent, 'is_legal') && (bool) $parent->is_legal;
            $admin = $this->authorizer->authorizePermission(
                $context,
                V2Permission::PublishContent,
                $isLegal,
                'content.legal.publish'
            );
            if ($parent->status === 'archived') {
                throw $this->conflict('CONTENT_ARCHIVED');
            }
            $version = DB::table('content_versions')
                ->where('public_id', $versionPublicId)
                ->where($ownerColumn, $parent->id)
                ->lockForUpdate()
                ->first();
            if ($version === null) {
                throw $this->notFound();
            }
            if (! in_array($version->status, ['draft', 'published'], true)) {
                throw $this->conflict('CONTENT_VERSION_ALREADY_PUBLISHED');
            }
            $this->assertPublishable($type, $version);
            $now = now()->startOfSecond();
            if ($version->status === 'draft') {
                DB::table('content_versions')->where('id', $version->id)->update([
                    'status' => 'published',
                    'published_by_admin_id' => $admin->id,
                    'published_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table($table)->where('id', $parent->id)->update([
                'status' => 'published',
                'published_version_id' => $version->id,
                'updated_at' => $now,
            ]);
            $this->auditContent(
                $isLegal ? 'content.legal_published' : 'content.published',
                $context,
                $type,
                $publicId,
                [
                    'version_public_id' => $versionPublicId,
                    'checksum_sha256' => $version->content_checksum,
                ]
            );

            return $this->contentDetail($context, $type, $publicId);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function unpublish(
        V2AdminAuthorizationContext $context,
        string $type,
        string $publicId
    ): array {
        return $this->changeParentState($context, $type, $publicId, 'draft');
    }

    /** @return array<string, mixed> */
    public function archive(
        V2AdminAuthorizationContext $context,
        string $type,
        string $publicId
    ): array {
        return $this->changeParentState($context, $type, $publicId, 'archived');
    }

    /** @return array<string, mixed> */
    public function contactList(
        V2AdminAuthorizationContext $context,
        ?string $cursor,
        int $limit
    ): array {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContact);
        $after = $this->cursor->decode($cursor);
        $limit = $this->limit($limit);
        $rows = DB::table('contact_inquiries')
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn (object $row): array => [
            'id' => $row->public_id,
            'receipt_code' => $row->receipt_code,
            'status' => $row->status,
            'authenticated' => $row->user_id !== null,
            'received_at' => CarbonImmutable::parse($row->received_at)->toIso8601String(),
        ])->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore
                ? $this->cursor->encode((int) $rows->get($limit - 1)->id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function contactDetail(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $admin = $this->authorizer->authorizePermission($context, V2Permission::ReadContact);
        $contact = DB::table('contact_inquiries')->where('public_id', $publicId)->first();
        if ($contact === null) {
            throw $this->notFound('CONTACT_NOT_FOUND');
        }
        $this->auditContact('contact.viewed', $context, $admin, $publicId);

        return [
            'id' => $contact->public_id,
            'receipt_code' => $contact->receipt_code,
            'status' => $contact->status,
            'authenticated' => $contact->user_id !== null,
            'name' => Crypt::decryptString($contact->name_ciphertext),
            'email' => Crypt::decryptString($contact->email_ciphertext),
            'phone' => $contact->phone_ciphertext === null
                ? null
                : Crypt::decryptString($contact->phone_ciphertext),
            'subject' => Crypt::decryptString($contact->subject_ciphertext),
            'body' => Crypt::decryptString($contact->body_ciphertext),
            'received_at' => CarbonImmutable::parse($contact->received_at)->toIso8601String(),
            'closed_at' => $contact->closed_at === null
                ? null
                : CarbonImmutable::parse($contact->closed_at)->toIso8601String(),
            'status_history' => DB::table('contact_status_histories')
                ->where('contact_inquiry_id', $contact->id)->orderBy('id')
                ->get(['from_status', 'to_status', 'reason_code', 'occurred_at'])
                ->map(static fn (object $row): array => [
                    'from_status' => $row->from_status,
                    'to_status' => $row->to_status,
                    'reason_code' => $row->reason_code,
                    'occurred_at' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
                ])->all(),
            'internal_notes' => DB::table('contact_internal_notes')
                ->where('contact_inquiry_id', $contact->id)->orderBy('id')
                ->get(['note_ciphertext', 'created_at'])
                ->map(static fn (object $row): array => [
                    'note' => Crypt::decryptString($row->note_ciphertext),
                    'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function updateContactStatus(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $toStatus,
        string $reasonCode
    ): array {
        $admin = $this->authorizer->authorizePermission($context, V2Permission::ManageContact);
        if (! preg_match('/\A[a-z][a-z0-9_.:-]{0,63}\z/', $reasonCode)) {
            throw $this->invalid('CONTACT_STATUS_INVALID');
        }

        return DB::transaction(function () use (
            $context,
            $publicId,
            $toStatus,
            $reasonCode,
            $admin
        ): array {
            $contact = DB::table('contact_inquiries')->where('public_id', $publicId)
                ->lockForUpdate()->first();
            if ($contact === null) {
                throw $this->notFound('CONTACT_NOT_FOUND');
            }
            if (! in_array($toStatus, self::CONTACT_TRANSITIONS[$contact->status] ?? [], true)) {
                throw $this->conflict('CONTACT_STATUS_TRANSITION_INVALID');
            }
            $now = now()->startOfSecond();
            DB::table('contact_inquiries')->where('id', $contact->id)->update([
                'status' => $toStatus,
                'assigned_admin_id' => $admin->id,
                'closed_at' => $toStatus === 'closed' ? $now : null,
                'updated_at' => $now,
            ]);
            $this->contactHistory(
                (int) $contact->id,
                $contact->status,
                $toStatus,
                $admin,
                $reasonCode,
                $context->requestId,
                $now
            );
            $this->auditContact('contact.status_changed', $context, $admin, $publicId, [
                'from_status' => $contact->status,
                'to_status' => $toStatus,
                'reason_code' => $reasonCode,
            ]);

            return [
                'id' => $publicId,
                'status' => $toStatus,
                'updated_at' => CarbonImmutable::parse($now)->toIso8601String(),
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function addInternalNote(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $note
    ): array {
        $admin = $this->authorizer->authorizePermission($context, V2Permission::ManageContact);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 5000) {
            throw $this->invalid('CONTACT_NOTE_INVALID');
        }

        return DB::transaction(function () use ($context, $publicId, $note, $admin): array {
            $contact = DB::table('contact_inquiries')->where('public_id', $publicId)
                ->lockForUpdate()->first();
            if ($contact === null) {
                throw $this->notFound('CONTACT_NOT_FOUND');
            }
            $now = now()->startOfSecond();
            DB::table('contact_internal_notes')->insert([
                'contact_inquiry_id' => $contact->id,
                'admin_id' => $admin->id,
                'note_ciphertext' => Crypt::encryptString($note),
                'request_id' => $context->requestId,
                'created_at' => $now,
            ]);
            $this->auditContact('contact.internal_note_added', $context, $admin, $publicId);

            return ['id' => $publicId, 'recorded' => true];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function requestReply(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $message
    ): array {
        $admin = $this->authorizer->authorizePermission($context, V2Permission::ManageContact);
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 5000) {
            throw $this->invalid('CONTACT_REPLY_INVALID');
        }

        return DB::transaction(function () use ($context, $publicId, $message, $admin): array {
            $contact = DB::table('contact_inquiries')->where('public_id', $publicId)
                ->lockForUpdate()->first();
            if ($contact === null) {
                throw $this->notFound('CONTACT_NOT_FOUND');
            }
            if ($contact->status === 'closed') {
                throw $this->conflict('CONTACT_CLOSED');
            }
            $replyPublicId = (string) Str::uuid7();
            $now = now()->startOfSecond();
            DB::table('contact_reply_requests')->insert([
                'public_id' => $replyPublicId,
                'contact_inquiry_id' => $contact->id,
                'requested_by_admin_id' => $admin->id,
                'message_ciphertext' => Crypt::encryptString($message),
                'request_id' => $context->requestId,
                'created_at' => $now,
            ]);
            if ($contact->status === 'new') {
                DB::table('contact_inquiries')->where('id', $contact->id)->update([
                    'status' => 'in_progress',
                    'assigned_admin_id' => $admin->id,
                    'updated_at' => $now,
                ]);
                $this->contactHistory(
                    (int) $contact->id,
                    $contact->status,
                    'in_progress',
                    $admin,
                    'reply_requested',
                    $context->requestId,
                    $now
                );
            }
            $this->outbox->enqueue(
                'contact.notification',
                'contact_inquiry',
                $publicId,
                'contact.reply.requested',
                [
                    'contact_public_id' => $publicId,
                    'reply_public_id' => $replyPublicId,
                ],
                'contact.reply.requested:'.$replyPublicId
            );
            $this->auditContact('contact.reply_requested', $context, $admin, $publicId);

            return ['id' => $replyPublicId, 'status' => 'queued'];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function changeParentState(
        V2AdminAuthorizationContext $context,
        string $type,
        string $publicId,
        string $target
    ): array {
        $this->authorizer->authorizePermission($context, V2Permission::PublishContent);
        [$table] = $this->type($type);

        return DB::transaction(function () use ($context, $type, $publicId, $target, $table): array {
            $parent = DB::table($table)->where('public_id', $publicId)
                ->lockForUpdate()->first();
            if ($parent === null) {
                throw $this->notFound();
            }
            $isLegal = property_exists($parent, 'is_legal') && (bool) $parent->is_legal;
            $this->authorizer->authorizePermission(
                $context,
                V2Permission::PublishContent,
                $isLegal,
                $target === 'archived' ? 'content.legal.archive' : 'content.legal.unpublish'
            );
            if ($parent->status === 'archived') {
                throw $this->conflict('CONTENT_ARCHIVED');
            }
            DB::table($table)->where('id', $parent->id)->update([
                'status' => $target,
                'published_version_id' => null,
                'updated_at' => now()->startOfSecond(),
            ]);
            $this->auditContent(
                $isLegal && $target === 'archived'
                    ? 'content.legal_archived'
                    : 'content.'.$target,
                $context,
                $type,
                $publicId
            );

            return $this->contentDetail($context, $type, $publicId);
        }, 3);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function createVersionRow(
        string $type,
        string $ownerColumn,
        int $ownerId,
        int $versionNumber,
        array $input,
        Admin $admin
    ): array {
        $title = $this->string($input['title'] ?? null, 1, 191);
        $summary = $this->nullableString($input['summary'] ?? null, 500);
        try {
            $body = $type === 'banner'
                ? null
                : $this->sanitizer->sanitize(
                    $this->string($input['body_html'] ?? null, 1, 100_000)
                );
        } catch (\RuntimeException) {
            throw $this->invalid('CONTENT_BODY_INVALID');
        }
        $link = $type === 'banner'
            ? $this->safeLink($input['link_url'] ?? null)
            : null;
        $sortOrder = $this->integer($input['sort_order'] ?? 0, 0, 1_000_000);
        $important = $type === 'notice' && ($input['is_important'] ?? false) === true;
        $start = $this->date($input['publish_start_at'] ?? null);
        $end = ($input['publish_end_at'] ?? null) === null
            ? null
            : $this->date($input['publish_end_at']);
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw $this->invalid('CONTENT_PUBLISH_PERIOD_INVALID');
        }
        $checksum = hash('sha256', json_encode([
            'title' => $title,
            'summary' => $summary,
            'body_html' => $body,
            'link_url' => $link,
            'sort_order' => $sortOrder,
            'is_important' => $important,
            'publish_start_at' => $start->utc()->format('Y-m-d\TH:i:s\Z'),
            'publish_end_at' => $end?->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $now = now()->startOfSecond();
        $attributes = [
            'public_id' => (string) Str::uuid7(),
            $ownerColumn => $ownerId,
            'version_number' => $versionNumber,
            'status' => 'draft',
            'title' => $title,
            'summary' => $summary,
            'body_html' => $body,
            'link_url' => $link,
            'sort_order' => $sortOrder,
            'is_important' => $important,
            'publish_start_at' => $start,
            'publish_end_at' => $end,
            'content_checksum' => $checksum,
            'created_by_admin_id' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $id = DB::table('content_versions')->insertGetId($attributes);
        $assetPublicId = $input['asset_id'] ?? null;
        if ($assetPublicId !== null) {
            if (! is_string($assetPublicId) || ! Str::isUuid($assetPublicId)) {
                throw $this->invalid('CONTENT_ASSET_INVALID');
            }
            $asset = DB::table('catalog_presentation_assets')
                ->where('public_id', $assetPublicId)->first();
            if ($asset === null || $asset->media_type !== 'image') {
                throw $this->invalid('CONTENT_ASSET_INVALID');
            }
            DB::table('content_version_assets')->insert([
                'content_version_id' => $id,
                'presentation_asset_id' => $asset->id,
                'usage_type' => $type === 'notice' ? 'thumbnail' : 'image',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->version((object) [
            'id' => $id,
            ...$attributes,
            'asset_public_id' => $assetPublicId,
        ], true);
    }

    private function assertPublishable(string $type, object $version): void
    {
        if ($version->publish_end_at !== null && CarbonImmutable::parse(
            $version->publish_end_at
        )->lessThanOrEqualTo(CarbonImmutable::parse($version->publish_start_at))) {
            throw $this->invalid('CONTENT_PUBLISH_PERIOD_INVALID');
        }
        $asset = DB::table('content_version_assets as cva')
            ->join('catalog_presentation_assets as a', 'a.id', '=', 'cva.presentation_asset_id')
            ->where('cva.content_version_id', $version->id)
            ->where('a.media_type', 'image')
            ->where('a.is_public', true)
            ->exists();
        if ($type === 'banner' && ! $asset) {
            throw $this->invalid('CONTENT_PUBLIC_ASSET_REQUIRED');
        }
        if (DB::table('content_version_assets')->where('content_version_id', $version->id)->exists()
            && ! $asset) {
            throw $this->invalid('CONTENT_ASSET_NOT_PUBLIC');
        }
    }

    /** @return array<string, mixed> */
    private function version(object $row, bool $admin): array
    {
        $result = [
            'id' => $row->public_id,
            'version_number' => (int) $row->version_number,
            'status' => $row->status,
            'title' => $row->title,
            'summary' => $row->summary,
            'body_html' => $row->body_html,
            'link_url' => $row->link_url,
            'sort_order' => (int) $row->sort_order,
            'is_important' => (bool) $row->is_important,
            'publish_start_at' => CarbonImmutable::parse($row->publish_start_at)
                ->toIso8601String(),
            'publish_end_at' => $row->publish_end_at === null
                ? null
                : CarbonImmutable::parse($row->publish_end_at)->toIso8601String(),
            'checksum_sha256' => $row->content_checksum,
        ];
        if ($admin) {
            $result['asset_id'] = property_exists($row, 'asset_public_id')
                ? $row->asset_public_id
                : DB::table('content_version_assets as cva')
                    ->join(
                        'catalog_presentation_assets as a',
                        'a.id',
                        '=',
                        'cva.presentation_asset_id'
                    )
                    ->where('cva.content_version_id', $row->id)
                    ->value('a.public_id');
            $publishedAt = property_exists($row, 'published_at')
                ? $row->published_at
                : null;
            $result['published_at'] = $publishedAt === null
                ? null
                : CarbonImmutable::parse($publishedAt)->toIso8601String();
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function latestVersion(object $row): ?array
    {
        if ($row->latest_version_public_id === null) {
            return null;
        }

        return [
            'id' => $row->latest_version_public_id,
            'version_number' => (int) $row->latest_version_number,
            'status' => $row->latest_version_status,
            'title' => $row->latest_title,
            'summary' => $row->latest_summary,
            'body_html' => $row->latest_body_html,
            'link_url' => $row->latest_link_url,
            'sort_order' => (int) $row->latest_sort_order,
            'is_important' => (bool) $row->latest_is_important,
            'publish_start_at' => CarbonImmutable::parse($row->latest_publish_start_at)
                ->toIso8601String(),
            'publish_end_at' => $row->latest_publish_end_at === null
                ? null
                : CarbonImmutable::parse($row->latest_publish_end_at)->toIso8601String(),
            'checksum_sha256' => $row->latest_content_checksum,
            'asset_id' => $row->latest_asset_public_id,
            'published_at' => $row->latest_published_at === null
                ? null
                : CarbonImmutable::parse($row->latest_published_at)->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $request */
    private function claimNoticeMutation(
        Admin $admin,
        string $idempotencyKey,
        array $request
    ): V2IdempotencyClaim {
        try {
            return $this->idempotency->claim(
                'content_notice_mutation',
                'admin',
                $admin->public_id,
                $idempotencyKey,
                $request
            );
        } catch (V2PointException $exception) {
            throw match ($exception->getMessage()) {
                'IDEMPOTENCY_KEY_REUSED' => $this->conflict(
                    'CONTENT_IDEMPOTENCY_KEY_REUSED'
                ),
                'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2ContentContactException(
                    'CONTENT_IDEMPOTENCY_REQUEST_IN_PROGRESS',
                    409,
                    'The Content request is still in progress.',
                    true
                ),
                default => $this->invalid('CONTENT_IDEMPOTENCY_KEY_INVALID'),
            };
        }
    }

    /** @return array{string, string, string} */
    private function type(string $type): array
    {
        if (! isset(self::TYPES[$type])) {
            throw $this->notFound();
        }

        return self::TYPES[$type];
    }

    private function contactHistory(
        int $contactId,
        ?string $from,
        string $to,
        Admin $admin,
        string $reason,
        string $requestId,
        mixed $now
    ): void {
        DB::table('contact_status_histories')->insert([
            'contact_inquiry_id' => $contactId,
            'from_status' => $from,
            'to_status' => $to,
            'actor_admin_id' => $admin->id,
            'reason_code' => $reason,
            'request_id' => $requestId,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function auditContent(
        string $action,
        V2AdminAuthorizationContext $context,
        string $type,
        string $publicId,
        array $metadata = []
    ): void {
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => str_replace('-', '_', $type),
            'target_public_id' => $publicId,
            'outcome' => 'success',
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function auditContact(
        string $action,
        V2AdminAuthorizationContext $context,
        Admin $admin,
        string $publicId,
        array $metadata = []
    ): void {
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'contact_inquiry',
            'target_public_id' => $publicId,
            'outcome' => 'success',
            'metadata' => $metadata,
        ]);
    }

    private function identifier(mixed $value): string
    {
        if (
            ! is_string($value)
            || ! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value)
            || strlen($value) > 128
        ) {
            throw $this->invalid('CONTENT_IDENTIFIER_INVALID');
        }

        return $value;
    }

    private function string(mixed $value, int $min, int $max): string
    {
        if (! is_string($value)) {
            throw $this->invalid();
        }
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            throw $this->invalid();
        }

        return $value;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        return $value === null || $value === '' ? null : $this->string($value, 1, $max);
    }

    private function safeLink(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $link = $this->string($value, 1, 2048);
        if (str_starts_with($link, '/') && ! str_starts_with($link, '//')) {
            return $link;
        }
        $scheme = parse_url($link, PHP_URL_SCHEME);
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw $this->invalid('CONTENT_LINK_INVALID');
        }

        return $link;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw $this->invalid();
        }

        return $value;
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            throw $this->invalid('CONTENT_PUBLISH_PERIOD_INVALID');
        }
        try {
            return CarbonImmutable::parse($value)->startOfSecond();
        } catch (\Throwable) {
            throw $this->invalid('CONTENT_PUBLISH_PERIOD_INVALID');
        }
    }

    private function limit(int $limit): int
    {
        $max = (int) config('v2_content_contact.cursor_maximum', 100);
        if ($limit < 1 || $limit > $max) {
            throw $this->invalid('CONTENT_PAGE_SIZE_INVALID');
        }

        return $limit;
    }

    private function invalid(string $code = 'CONTENT_REQUEST_INVALID'): V2ContentContactException
    {
        return new V2ContentContactException($code, 422, 'The Content request is invalid.');
    }

    private function conflict(string $code): V2ContentContactException
    {
        return new V2ContentContactException($code, 409, 'The Content operation conflicts.');
    }

    private function notFound(string $code = 'CONTENT_NOT_FOUND'): V2ContentContactException
    {
        return new V2ContentContactException($code, 404, 'The requested resource was not found.');
    }
}

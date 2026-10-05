<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\GachaNoticeDefault;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class V2GachaNoticeDefaultService
{
    private const SCOPES = ['standard', 'login'];

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox,
    ) {}

    public function read(V2AdminAuthorizationContext $context): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadCatalog);

        return $this->serialize($this->settings());
    }

    public function update(V2AdminAuthorizationContext $context, string $idempotencyKey, array $input): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManageCatalog);
        $payload = $this->validate($input);
        try {
            return DB::transaction(function () use ($context, $admin, $idempotencyKey, $payload): array {
                $claim = $this->idempotency->claim('catalog.gacha_notices.update', 'admin', $admin->public_id, $idempotencyKey, $payload);
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! isset($response['data'])) {
                        throw $this->unavailable();
                    }

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }
                $settings = $this->settings(true);
                foreach (self::SCOPES as $scope) {
                    if ($settings[$scope]->revision !== $payload[$scope]['expected_revision']) {
                        throw new V2CatalogException('GACHA_NOTICE_DEFAULT_REVISION_CONFLICT', 409, 'The gacha notice defaults were updated by another operation.');
                    }
                }
                foreach (self::SCOPES as $scope) {
                    $setting = $settings[$scope];
                    $before = $this->auditValues($setting);
                    $setting->forceFill([
                        'default_notices' => $payload[$scope]['default_notices'],
                        'revision' => $setting->revision + 1,
                        'updated_by_admin_id' => $admin->getKey(),
                        'updated_at' => now()->startOfSecond(),
                    ])->save();
                    $this->audit->record('catalog.gacha_notice_default.updated', [
                        'request_id' => $context->requestId,
                        'actor_type' => 'admin', 'actor_public_id' => $admin->public_id,
                        'actor_role' => $admin->role->value, 'auth_realm' => 'admin',
                        'session_correlation_hash' => $context->sessionCorrelationHash,
                        'target_type' => 'gacha_notice_default', 'target_public_id' => $setting->public_id,
                        'before' => $before, 'after' => $this->auditValues($setting), 'outcome' => 'success',
                    ]);
                    $this->outbox->enqueue('gacha-notice-default-updated', 'gacha_notice_default', $setting->public_id,
                        'catalog.gacha_notice_default.updated', ['scope' => $scope, 'revision' => $setting->revision],
                        'gacha-notice-default:'.$setting->public_id.':'.$setting->revision);
                }
                $data = $this->serialize($settings);
                $this->idempotency->complete($claim->record, 'gacha_notice_default', $settings['standard']->public_id, ['data' => $data]);

                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw new V2CatalogException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED' ? 'IDEMPOTENCY_KEY_REUSED' : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409, 'The gacha notice defaults update conflicts with another request.'
            );
        }
    }

    private function settings(bool $lock = false): Collection
    {
        $query = GachaNoticeDefault::query()->orderBy('scope');
        if ($lock) {
            $query->lockForUpdate();
        }
        $settings = $query->get()->keyBy('scope');
        if ($settings->count() !== 2 || ! $settings->has(self::SCOPES)) {
            throw $this->unavailable();
        }

        return $settings;
    }

    private function validate(array $input): array
    {
        if (count($input) !== 2 || array_diff(self::SCOPES, array_keys($input)) !== []) {
            throw $this->invalid();
        }
        $payload = [];
        foreach (self::SCOPES as $scope) {
            $row = $input[$scope];
            if (! is_array($row) || count($row) !== 2
                || array_diff(['default_notices', 'expected_revision'], array_keys($row)) !== []
                || ! is_int($row['expected_revision']) || $row['expected_revision'] < 1) {
                throw $this->invalid();
            }
            $notices = $row['default_notices'] === null ? null : trim(V2CatalogPlainText::normalize($row['default_notices'], 0, 10000));
            $payload[$scope] = ['default_notices' => $notices === '' ? null : $notices, 'expected_revision' => $row['expected_revision']];
        }

        return $payload;
    }

    private function serialize(Collection $settings): array
    {
        $data = [];
        foreach (self::SCOPES as $scope) {
            $data[$scope] = ['default_notices' => $settings[$scope]->default_notices, 'revision' => $settings[$scope]->revision];
        }

        return $data;
    }

    private function auditValues(GachaNoticeDefault $setting): array
    {
        return ['scope' => $setting->scope, 'revision' => $setting->revision,
            'notices_sha256' => $setting->default_notices === null ? null : hash('sha256', $setting->default_notices)];
    }

    private function invalid(): V2CatalogException
    {
        return new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The Catalog mutation request is invalid.');
    }

    private function unavailable(): V2CatalogException
    {
        return new V2CatalogException('GACHA_NOTICE_DEFAULT_UNAVAILABLE', 503, 'The gacha notice defaults are unavailable.');
    }
}

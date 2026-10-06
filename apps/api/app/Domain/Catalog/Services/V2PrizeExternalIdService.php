<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use Illuminate\Support\Facades\DB;

final class V2PrizeExternalIdService
{
    public function checkAndAudit(object $gacha, object $version, object $prize, ?string $externalId, V2AdminAuthorizationContext $context, bool $created = false): void
    {
        $before = $created ? null : $prize->external_id;
        if ($gacha->first_published_at !== null && $before !== null && $before !== $externalId) {
            throw new V2CatalogException('CATALOG_PRIZE_EXTERNAL_ID_IMMUTABLE', 409, 'A previously published Prize external ID cannot be changed or cleared.');
        }
        $versionIds = DB::table('catalog_gacha_version_prizes')->where('prize_id', $prize->id)
            ->pluck('gacha_version_id')->push($version->id)->unique()->all();
        DB::table('catalog_gacha_versions')->whereIn('id', $versionIds)->orderBy('id')->lockForUpdate()->get();
        if ($externalId !== null && DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->whereIn('relation.gacha_version_id', $versionIds)->where('prize.external_id', $externalId)
            ->where('prize.id', '<>', $prize->id)->exists()) {
            throw new V2CatalogException('CATALOG_PRIZE_EXTERNAL_ID_CONFLICT', 409, 'The external ID is already used in this Gacha Version.');
        }
        if ($before === $externalId) {
            return;
        }
        app(V2AuditLogService::class)->record('catalog.prize.external_id_changed', [
            'request_id' => $context->requestId,
            'actor_type' => 'admin', 'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value, 'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'catalog_prize', 'target_public_id' => $prize->public_id,
            'before' => ['external_id' => $before],
            'after' => ['external_id' => $externalId],
        ]);
    }

    public function assertDistinct(array $externalIds): void
    {
        $assigned = array_values(array_filter($externalIds, static fn (?string $value): bool => $value !== null));
        if (count($assigned) !== count(array_unique($assigned, SORT_STRING))) {
            throw new V2CatalogException('CATALOG_PRIZE_EXTERNAL_ID_CONFLICT', 409, 'The external ID is already used in this Gacha Version.');
        }
    }
}

<?php

namespace App\Domain\Audit\V2\Services;

use App\Models\V2\AuditDailyDigest;
use App\Models\V2\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class V2AuditDailyDigestService
{
    public function __construct(
        private readonly V2AuditHasher $hasher,
        private readonly V2AuditChainVerifier $chain,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function generate(string $businessDate): AuditDailyDigest
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $businessDate);
        if ($date === false || $date->format('Y-m-d') !== $businessDate) {
            throw new RuntimeException('Audit business date is invalid.');
        }

        return DB::transaction(function () use ($businessDate): AuditDailyDigest {
            $this->audit->lockChain();
            if (! $this->chain->verify()) {
                throw new RuntimeException('Audit hash chain verification failed.');
            }
            $records = AuditLog::query()
                ->whereDate('business_date', $businessDate)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($records->isEmpty()) {
                throw new RuntimeException('Audit day has no records.');
            }
            if (AuditDailyDigest::query()->where('business_date', $businessDate)->exists()) {
                throw new RuntimeException('Audit daily digest already exists.');
            }
            $version = $this->hasher->activeKeyVersion();
            $payload = [
                'business_date' => $businessDate,
                'record_count' => $records->count(),
                'first_record_hash' => $records->first()->record_hash,
                'last_record_hash' => $records->last()->record_hash,
                'hmac_key_version' => $version,
            ];
            $digest = new AuditDailyDigest();
            $digest->forceFill([
                ...$payload,
                'digest_hash' => $this->hasher->digest($payload, $version),
                'generated_at' => now()->startOfSecond(),
            ]);
            $digest->save();

            return $digest->refresh();
        }, 3);
    }
}

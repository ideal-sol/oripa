<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LoginProbabilityService
{
    public function isLoginVersion(int $versionId): bool
    {
        return DB::table('catalog_gacha_versions as version')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'version.gacha_id')
            ->where('version.id', $versionId)
            ->whereIn('gacha.gacha_type', ['login_daily', 'signup_once'])->exists();
    }

    public function save(int $versionId, array $rates): object
    {
        V2FixedPercentage::assertTotal(array_values($rates));
        $now = V2DatabaseTimestamp::format(now()->startOfSecond());
        $probability = DB::table('catalog_probability_versions')
            ->where('gacha_version_id', $versionId)->whereNull('archived_at')->lockForUpdate()->first();
        if ($probability !== null && $probability->status !== 'draft') {
            throw $this->invalid();
        }
        if ($probability === null) {
            $probabilityId = DB::table('catalog_probability_versions')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'gacha_version_id' => $versionId,
                'version_number' => 1, 'status' => 'draft', 'snapshot_sha256' => str_repeat('0', 64),
                'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $stageId = DB::table('catalog_probability_stages')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'probability_version_id' => $probabilityId,
                'code' => '__login_fixed_10_v1', 'display_name' => 'Fixed percentage',
                'condition_type' => 'sold_count', 'min_draw_number' => 1, 'max_draw_number' => null,
                'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $probabilityId = (int) $probability->id;
            $stageId = (int) DB::table('catalog_probability_stages')
                ->where('probability_version_id', $probabilityId)->value('id');
            DB::table('catalog_probability_entries')->where('probability_stage_id', $stageId)->delete();
        }
        $sortOrder = 0;
        foreach ($rates as $relationId => $units) {
            DB::table('catalog_probability_entries')->insert([
                'probability_stage_id' => $stageId, 'result_type' => 'prize',
                'gacha_version_prize_id' => $relationId, 'point_amount' => null,
                'probability_ppm' => null, 'rate_units' => $units, 'sort_order' => ++$sortOrder,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $snapshot = $this->snapshot($probabilityId, $versionId);
        DB::table('catalog_probability_versions')->where('id', $probabilityId)->update([
            'snapshot_sha256' => $snapshot['checksum'],
            'revision' => $probability === null ? 2 : (int) $probability->revision + 1,
            'updated_at' => $now,
        ]);

        return DB::table('catalog_probability_versions')->where('id', $probabilityId)->firstOrFail();
    }

    public function prepare(int $versionId, ?int $pinnedId = null): object
    {
        $probability = DB::table('catalog_probability_versions')
            ->where('gacha_version_id', $versionId)->whereNull('archived_at')->lockForUpdate()->first();
        if ($probability === null || ($pinnedId !== null && (int) $probability->id !== $pinnedId)) {
            throw $this->invalid();
        }
        $snapshot = $this->snapshot((int) $probability->id, $versionId);
        if (! hash_equals((string) $probability->snapshot_sha256, $snapshot['checksum'])) {
            throw $this->invalid();
        }

        return $probability;
    }

    public function snapshot(int $probabilityId, int $versionId): array
    {
        $stages = DB::table('catalog_probability_stages')->where('probability_version_id', $probabilityId)->get();
        $stage = $stages->first();
        if ($stages->count() !== 1 || $stage->code !== '__login_fixed_10_v1' || $stage->condition_type !== 'sold_count'
            || (int) $stage->min_draw_number !== 1 || $stage->max_draw_number !== null
            || DB::table('catalog_minimum_guarantees')->where('probability_stage_id', $stage->id)->exists()) {
            throw $this->invalid();
        }
        $entries = DB::table('catalog_probability_entries as entry')
            ->join('catalog_gacha_version_prizes as relation', 'relation.id', '=', 'entry.gacha_version_prize_id')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('entry.probability_stage_id', $stage->id)->orderBy('entry.sort_order')->orderBy('entry.id')
            ->get(['entry.*', 'relation.gacha_version_id', 'prize.public_id as prize_public_id']);
        $relationIds = DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $versionId)->orderBy('id')->pluck('id')->map(fn ($value): int => (int) $value)->all();
        $rates = [];
        $canonical = [];
        foreach ($entries as $entry) {
            $relationId = (int) $entry->gacha_version_prize_id;
            if ((int) $entry->gacha_version_id !== $versionId || isset($rates[$relationId])
                || $entry->result_type !== 'prize' || $entry->probability_ppm !== null || $entry->rate_units === null) {
                throw $this->invalid();
            }
            $rates[$relationId] = (int) $entry->rate_units;
            $canonical[] = ['prize_id' => $entry->prize_public_id, 'rate_units' => (int) $entry->rate_units];
        }
        $actualIds = array_keys($rates);
        sort($actualIds);
        if ($actualIds !== $relationIds || count($entries) !== DB::table('catalog_probability_entries')->where('probability_stage_id', $stage->id)->count()) {
            throw $this->invalid();
        }
        V2FixedPercentage::assertTotal(array_values($rates));

        return ['rates' => $rates, 'stage' => $stage, 'checksum' => hash('sha256', json_encode([
            'algorithm' => 'login_fixed_10_v1', 'scale' => V2FixedPercentage::SCALE, 'entries' => $canonical,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    private function invalid(): V2CatalogException
    {
        return new V2CatalogException('CATALOG_PROBABILITY_PUBLISH_INVALID', 409, 'The login fixed Probability snapshot is invalid.');
    }
}

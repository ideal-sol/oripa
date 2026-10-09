<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Support\V2ExternalId;
use Illuminate\Support\Facades\DB;

final class V2PrizeImportPlan
{
    public function build(object $gacha, object $version, array $csv, bool $lock): array
    {
        $relations = DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $version->id)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $prizes = DB::table('catalog_prizes')->whereIn('id', $relations->pluck('prize_id'))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $inventories = DB::table('prize_inventories')->whereIn('gacha_version_prize_id', $relations->pluck('id'))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('gacha_version_prize_id');
        $masters = DB::table('catalog_rank_masters as master')
            ->join('catalog_rank_master_revisions as revision', 'revision.id', '=', 'master.current_revision_id')
            ->orderBy('master.id')->when($lock, fn ($query) => $query->lock('FOR SHARE OF master'))
            ->get(['master.*', 'revision.rank_name', 'revision.lineup_image_asset_id', 'revision.result_image_asset_id']);
        $ranks = DB::table('catalog_gacha_ranks')->where('gacha_id', $gacha->id)
            ->orderBy('id')->when($lock, fn ($query) => $query->sharedLock())->get()->keyBy('rank_master_id');
        $library = app(V2ContentContactAdminService::class)->prizeLibraryEntries(array_column(array_column($csv['rows'], 'values'), '管理ID'), $lock);
        $existing = [];
        foreach ($relations as $relation) {
            $prize = $prizes->get($relation->prize_id);
            if ($prize->archived_at !== null) {
                throw new V2CatalogException('CATALOG_RESOURCE_ARCHIVED', 409, 'Archive済みの景品は取り込めません。');
            }
            if ($prize->external_id !== null) {
                $existing[$prize->external_id] = [$prize, $relation, $inventories->get($relation->id)];
            }
        }
        $errors = [];
        $warnings = [];
        $rows = [];
        $operations = [];
        $seen = [];
        $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0];
        $sort = (int) ($relations->max('sort_order') ?? 0);
        $total = 0;
        foreach ($csv['rows'] as $record) {
            $values = $record['values'];
            $row = $record['row'];
            $startErrors = count($errors);
            $externalId = null;
            try {
                $externalId = V2ExternalId::normalize($values['管理ID'], new \InvalidArgumentException());
            } catch (\InvalidArgumentException) {
            }
            if ($externalId === null) {
                $errors[] = $this->message($row, '管理ID', 'EXTERNAL_ID_INVALID', '管理IDは半角英数字・ハイフン・アンダースコア・ピリオドの1〜64文字です。');
            } elseif (isset($seen[$externalId])) {
                $errors[] = $this->message($row, '管理ID', 'EXTERNAL_ID_DUPLICATED', 'CSV内で管理IDが重複しています。');
            }
            $seen[$externalId ?? ''] = true;
            [$prize, $relation, $inventory] = $existing[$externalId ?? ''] ?? [null, null, null];
            $entry = $library[$externalId ?? ''] ?? null;
            $asset = $entry['asset'] ?? null;
            if ($entry === null || $entry['version'] === null || $asset === null || $asset->archived_at !== null || ! $asset->is_public || $asset->media_type !== 'image') {
                $errors[] = $this->message($row, '管理ID', 'LIBRARY_ENTRY_NOT_FOUND', '利用可能な画像を持つ景品画像ライブラリがありません。');
            }
            $matchedMasters = $masters->filter(fn (object $master): bool => $master->status === 'active' && $master->rank_name === $values['ランク']);
            $master = $matchedMasters->count() === 1 ? $matchedMasters->first() : null;
            $gachaRank = $master === null ? null : $ranks->get($master->id);
            if ($master === null) {
                $errors[] = $this->message($row, 'ランク', 'RANK_NOT_FOUND', '現在有効なランク名と完全一致するランクを指定してください。');
            } else {
                try {
                    V2GachaPrizeRules::assertRankReady($master, $gachaRank);
                } catch (V2CatalogException) {
                    $errors[] = $this->message($row, 'ランク', 'RANK_NOT_READY', 'ランクの演出動画を設定してください。');
                }
                if ($prize !== null && ($gachaRank === null || (int) $prize->gacha_rank_id !== (int) $gachaRank->id)) {
                    $errors[] = $this->message($row, 'ランク', 'RANK_CHANGE_NOT_SUPPORTED', '既存景品のランクはCSVから変更できません。');
                }
            }
            $after = [];
            foreach (['枚数' => 'quantity', '交換ポイント' => 'exchange_points', '原価' => 'cost_price', '表示順' => 'sort_order'] as $column => $field) {
                if (! array_key_exists($column, $values)) {
                    continue;
                }
                $value = $values[$column];
                if ($column === '表示順' && $value === '') {
                    continue;
                }
                $normalized = ltrim($value, '0');
                if (! preg_match('/\A[0-9]+\z/', $value) || strlen($normalized) > strlen((string) PHP_INT_MAX)
                    || (strlen($normalized) === strlen((string) PHP_INT_MAX) && strcmp($normalized, (string) PHP_INT_MAX) > 0)
                    || ($column === '表示順' && ((int) $value < 1 || (int) $value > 2147483647))) {
                    $errors[] = $this->message($row, $column, 'VALUE_INVALID', $column.'は範囲内の半角整数で指定してください。');
                } else {
                    $after[$field] = (int) $value;
                }
            }
            if (isset($values['発送のみ']) && $values['発送のみ'] !== '') {
                if (! in_array($values['発送のみ'], ['1', '0', 'TRUE', 'FALSE'], true)) {
                    $errors[] = $this->message($row, '発送のみ', 'VALUE_INVALID', '発送のみは1、0、TRUE、FALSEのいずれかです。');
                } else {
                    $after['shipping_only'] = in_array($values['発送のみ'], ['1', 'TRUE'], true);
                }
            }
            if (count($errors) !== $startErrors) {
                continue;
            }
            $total = min((int) $version->total_count + 1, $total + min($after['quantity'], (int) $version->total_count + 1));
            $before = $relation === null ? [] : [
                'quantity' => (int) ($inventory?->total_quantity ?? $relation->initial_inventory),
                'exchange_points' => (int) $relation->exchange_points, 'cost_price' => (int) $relation->cost_price,
                'shipping_only' => (bool) $relation->shipping_only, 'sort_order' => (int) $relation->sort_order,
            ];
            if ($inventory !== null && $after['quantity'] < (int) $inventory->awarded_count + (int) $inventory->withdrawn_quantity) {
                $errors[] = $this->message($row, '枚数', 'VALUE_INVALID', '枚数は確定済み当選数と除外在庫の合計より少なくできません。');
            }
            if ($prize === null) {
                $after += ['shipping_only' => false, 'sort_order' => ++$sort];
                if ($after['sort_order'] > 2147483647) {
                    $errors[] = $this->message($row, '表示順', 'VALUE_INVALID', '表示順の上限を超えています。表示順を明示してください。');
                }
                $after += ['name' => $entry['version']->title, 'image' => $asset->public_id, 'rank' => $master->rank_name];
            } else {
                $after += $before;
            }
            $changes = [];
            foreach ($after as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $previous = $before[$field] ?? null;
                    $changes[] = ['field' => $field,
                        'before' => is_int($previous) && $previous > 9007199254740991 ? (string) $previous : $previous,
                        'after' => is_int($value) && $value > 9007199254740991 ? (string) $value : $value];
                }
            }
            $action = $prize === null ? 'create' : ($changes === [] ? 'unchanged' : 'update');
            $summary[$action]++;
            $rows[] = ['row' => $row, 'external_id' => $externalId, 'action' => $action, 'changes' => $changes];
            $operations[] = compact('action', 'externalId', 'prize', 'relation', 'inventory', 'master', 'gachaRank', 'asset', 'after');
            if ($after['quantity'] === 0) {
                $warnings[] = $this->message($row, '枚数', 'ZERO_QUANTITY', '枚数が0です。');
            }
            if (isset($values['カード名']) && $values['カード名'] !== '' && $values['カード名'] !== $entry['version']->title) {
                $warnings[] = $this->message($row, 'カード名', 'CARD_NAME_MISMATCH', 'カード名がライブラリのタイトルと異なります。');
            }
            if ($prize !== null && $relation->display_name !== $entry['version']->title) {
                $warnings[] = $this->message($row, '管理ID', 'PRIZE_NAME_MISMATCH', '既存景品名はライブラリと異なります。既存名を維持します。');
            }
            if ($prize !== null && (int) $relation->presentation_asset_id !== (int) $asset->id) {
                $warnings[] = $this->message($row, '管理ID', 'PRIZE_IMAGE_MISMATCH', '既存画像はライブラリと異なります。既存画像を維持します。');
            }
        }
        $missing = [];
        foreach ($relations as $relation) {
            $prize = $prizes->get($relation->prize_id);
            if ($prize->external_id === null || ! isset($seen[$prize->external_id])) {
                $rank = $ranks->firstWhere('id', $relation->gacha_rank_id);
                $missing[] = ['external_id' => $prize->external_id, 'name' => $relation->display_name,
                    'rank' => $masters->firstWhere('id', $rank?->rank_master_id)?->rank_name ?? $relation->rank_display_name ?? '',
                    'quantity' => (int) ($inventories->get($relation->id)?->total_quantity ?? $relation->initial_inventory)];
            }
        }
        if ($missing !== []) {
            $errors[] = [...$this->message(null, null, 'PRIZE_MISSING_FROM_CSV', '下書きにある景品がCSVにありません。'), 'items' => $missing];
        }
        try {
            V2GachaPrizeRules::assertCapacity($total, $total, (int) $version->total_count);
        } catch (V2CatalogException) {
            $errors[] = $this->message(null, '枚数', 'INVENTORY_TOTAL_EXCEEDED', '枚数の合計がガチャの総口数を超えています。');
        }
        $state = [$gacha, $version, $csv, $relations->all(), $prizes->all(), $inventories->all(), $masters->all(), $ranks->all(), $library, $rows, $errors];

        return ['public' => ['plan_checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)),
            'summary' => $summary, 'rows' => $rows, 'warnings' => $warnings], 'errors' => $errors, 'operations' => $operations];
    }

    public function assertValid(array $plan): void
    {
        if ($plan['errors'] !== []) {
            throw new V2CatalogException('CSV_VALIDATION_FAILED', 422, 'CSVを確認してください。', [
                'errors' => array_slice($plan['errors'], 0, 200), 'error_count' => count($plan['errors']),
            ]);
        }
    }

    private function message(?int $row, ?string $column, string $code, string $message): array
    {
        return compact('row', 'column', 'code', 'message');
    }
}

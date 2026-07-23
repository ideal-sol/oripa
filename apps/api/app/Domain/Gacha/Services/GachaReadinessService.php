<?php

namespace App\Domain\Gacha\Services;

use App\Models\Gacha;
use Illuminate\Support\Collection;

class GachaReadinessService
{
    public function __construct(
        private readonly GachaProfitSimulationService $profitSimulationService,
    ) {
    }
    public function inspect(Gacha $gacha): array
    {
        $gacha->loadCount([
            'ranks',
            'prizes',
            'prizes as active_prizes_count' => fn ($query) => $query
                ->where('is_active', true)
                ->where('is_visible', true),
        ])->load([
            'currentProbabilityVersion',
            'ranks' => fn ($query) => $query->where('is_visible', true),
            'ranks.rankImageAsset',
            'ranks.rankImageAssets',
            'ranks.drawVideoAsset',
            'ranks.drawVideoAssets',
            'prizes' => fn ($query) => $query
                ->where('is_active', true)
                ->where('is_visible', true),
        ]);

        $profitSimulation = $this->profitSimulationService->simulate($gacha);
        $expected = $profitSimulation['expected'];
        $profit = $profitSimulation['profit'];

        $checks = [
            $this->check('basic_fields', '基本情報', $this->hasBasicFields($gacha), 'タイトル、slug、カテゴリ、価格、総口数が必要です。'),
            $this->check('gacha_image', 'メイン画像', $this->hasValue($gacha->main_image_url), 'メイン画像を登録してください。'),
            $this->check('sale_count', '販売口数', (int) $gacha->sold_count < (int) $gacha->total_count, '販売済み数が総口数未満である必要があります。'),
            $this->check('schedule', '販売期間', $this->hasValidSchedule($gacha), '終了日時は開始日時以降にしてください。'),
            $this->check('ranks', 'ランク', (int) $gacha->ranks_count > 0, '少なくとも1つのランクが必要です。'),
            $this->check('rank_images', 'ランク画像', $this->allRanksHaveImages($gacha), '表示中ランクには画像を登録してください。'),
            $this->check('rank_draw_videos', 'ランク演出動画', $this->allRanksHaveDrawVideos($gacha), '表示中ランクには抽選演出動画を登録してください。'),
            $this->check('prizes', '景品', (int) $gacha->active_prizes_count > 0, '有効かつ表示中の景品が必要です。'),
            $this->check('prize_images', '景品画像', $this->allPrizesHaveImages($gacha), '有効かつ表示中の景品には画像を登録してください。'),
            $this->check('probability_version', '確率公開', $gacha->current_probability_version_id !== null, '公開済みの確率バージョンが必要です。'),
            $this->check('max_profit', '最大原価利益', (int) $profit['projected_profit'] >= 0, '完売時の最大原価シナリオが赤字です。'),
            $this->check('expected_profit', '期待利益', $expected['available'] === true && (int) $expected['expected_profit'] >= 0, '公開済み確率の期待利益が赤字、または未計算です。', 'warning'),
            $this->check('target_margin', '目標粗利', $profit['meets_target'] !== false, '目標粗利率を下回っています。'),
        ];

        return [
            'gacha_id' => (int) $gacha->id,
            'ready' => collect($checks)->every(fn (array $check): bool => $check['passed'] || $check['severity'] === 'warning'),
            'checks' => $checks,
        ];
    }
    public function failedChecks(Gacha $gacha): Collection
    {
        return collect($this->inspect($gacha)['checks'])
            ->filter(fn (array $check): bool => ! $check['passed'] && $check['severity'] !== 'warning')
            ->values();
    }
    private function check(string $key, string $label, bool $passed, string $message, string $severity = 'blocking'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'message' => $passed ? null : $message,
            'severity' => $severity,
        ];
    }

    private function hasBasicFields(Gacha $gacha): bool
    {
        return $this->hasValue($gacha->title)
            && $this->hasValue($gacha->slug)
            && $gacha->category_id !== null
            && (int) $gacha->price >= 0
            && (int) $gacha->total_count > 0;
    }

    private function hasValidSchedule(Gacha $gacha): bool
    {
        if ($gacha->start_at === null || $gacha->end_at === null) {
            return true;
        }

        return $gacha->end_at->greaterThanOrEqualTo($gacha->start_at);
    }

    private function allRanksHaveImages(Gacha $gacha): bool
    {
        return $gacha->ranks->isNotEmpty()
            && $gacha->ranks->every(fn ($rank): bool => $this->hasValue($rank->effectiveImageUrl()));
    }

    private function allRanksHaveDrawVideos(Gacha $gacha): bool
    {
        return $gacha->ranks->isNotEmpty()
            && $gacha->ranks->every(fn ($rank): bool => $this->hasValue($rank->effectiveDrawVideoUrl()));
    }

    private function allPrizesHaveImages(Gacha $gacha): bool
    {
        return $gacha->prizes->isNotEmpty()
            && $gacha->prizes->every(fn ($prize): bool => $this->hasValue($prize->image_url));
    }

    private function hasValue(?string $value): bool
    {
        return trim((string) $value) !== '';
    }
}

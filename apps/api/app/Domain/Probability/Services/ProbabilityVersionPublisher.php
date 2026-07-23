<?php

namespace App\Domain\Probability\Services;

use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaProbabilityVersion;
use Illuminate\Support\Facades\DB;

class ProbabilityVersionPublisher
{
    public function __construct(
        private readonly ProbabilityValidator $validator,
        private readonly SnapshotHasher $snapshotHasher,
    ) {
    }
    public function publish(Gacha $gacha, array $stages, ?AdminUser $publisher = null, ?string $changeReason = null): GachaProbabilityVersion
    {
        return DB::transaction(function () use ($gacha, $stages, $publisher, $changeReason): GachaProbabilityVersion {
            // ガチャをロックして、同時公開時にもバージョン番号が重複しないようにする。
            $lockedGacha = Gacha::query()
                ->whereKey($gacha->id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedStages = $this->validator->validateForPublish($lockedGacha, $stages);
            $snapshotHash = $this->snapshotHasher->hash($normalizedStages);

            // 公開済みバージョンは変更せず、新しいスナップショットを積み上げる。
            $nextVersionNumber = ((int) GachaProbabilityVersion::query()
                ->where('gacha_id', $lockedGacha->id)
                ->max('version_number')) + 1;

            $version = GachaProbabilityVersion::query()->create([
                'gacha_id' => $lockedGacha->id,
                'version_number' => $nextVersionNumber,
                'status' => ProbabilityVersionStatus::Draft,
                'snapshot_hash' => $snapshotHash,
                'published_by' => $publisher?->id,
                'change_reason' => $changeReason,
            ]);

            foreach ($normalizedStages as $stagePayload) {
                $stage = $version->stages()->create([
                    'stage_key' => $stagePayload['stage_key'],
                    'name' => $stagePayload['name'],
                    'condition_type' => $stagePayload['condition_type'],
                    'min_draw_number' => $stagePayload['min_draw_number'],
                    'max_draw_number' => $stagePayload['max_draw_number'],
                    'sort_order' => $stagePayload['sort_order'],
                ]);

                foreach ($stagePayload['probabilities'] as $probabilityPayload) {
                    $stage->probabilities()->create([
                        'prize_id' => $probabilityPayload['prize_id'],
                        'is_minimum_guarantee' => $probabilityPayload['is_minimum_guarantee'],
                        'probability_ppm' => $probabilityPayload['probability_ppm'],
                    ]);
                }
            }

            $version->forceFill([
                'status' => ProbabilityVersionStatus::Published,
                'published_at' => now(),
            ])->save();

            // 抽選時はガチャに紐づく現行公開バージョンだけを参照する。
            $lockedGacha->forceFill([
                'current_probability_version_id' => $version->id,
            ])->save();

            return $version->refresh()->load('stages.probabilities');
        });
    }
}

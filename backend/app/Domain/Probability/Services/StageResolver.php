<?php

namespace App\Domain\Probability\Services;

use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use App\Domain\Probability\Exceptions\ProbabilityStageNotFoundException;
use App\Models\GachaProbabilityVersionStage;

class StageResolver
{
    public function resolve(int $probabilityVersionId, int $drawSequenceNumber): GachaProbabilityVersionStage
    {
        if ($drawSequenceNumber < 1) {
            throw new ProbabilityStageNotFoundException('Draw sequence number must be greater than or equal to 1.');
        }

        // 抽選通し番号に対応する公開済みステージだけを採用する。
        $stage = GachaProbabilityVersionStage::query()
            ->where('probability_version_id', $probabilityVersionId)
            ->where('min_draw_number', '<=', $drawSequenceNumber)
            ->where(function ($query) use ($drawSequenceNumber): void {
                $query
                    ->whereNull('max_draw_number')
                    ->orWhere('max_draw_number', '>=', $drawSequenceNumber);
            })
            ->whereHas('version', function ($query): void {
                $query->where('status', ProbabilityVersionStatus::Published->value);
            })
            ->with(['probabilities' => fn ($query) => $query->orderBy('id')])
            ->orderBy('min_draw_number')
            ->first();

        if (! $stage) {
            throw new ProbabilityStageNotFoundException(
                "Published probability stage was not found for draw sequence {$drawSequenceNumber}."
            );
        }

        return $stage;
    }
}

<?php

namespace App\Domain\Probability\Services;

use App\Domain\Probability\DTO\ProbabilityRange;
use App\Domain\Probability\DTO\ProbabilityRangeEntry;
use App\Models\GachaProbabilityVersionStage;
use App\Models\GachaPrize;
use LogicException;

class ProbabilityRangeBuilder
{
    public function build(GachaProbabilityVersionStage $stage): ProbabilityRange
    {
        $probabilities = $stage->probabilities()->orderBy('id')->get();
        $minimumRow = $probabilities->firstWhere('is_minimum_guarantee', true);

        if (! $minimumRow) {
            throw new LogicException('Probability stage must include a minimum guarantee row.');
        }

        $prizeIds = $probabilities
            ->where('is_minimum_guarantee', false)
            ->pluck('prize_id')
            ->filter()
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();

        $prizesById = GachaPrize::query()
            ->whereIn('id', $prizeIds)
            ->get()
            ->keyBy('id');

        $entries = [];
        $minimumGuaranteePpm = (int) $minimumRow->probability_ppm;

        // 売り切れ・無効化された景品の確率は最低保証へ寄せ、100%の抽選範囲を維持する。
        foreach ($probabilities as $probability) {
            if ($probability->is_minimum_guarantee) {
                continue;
            }

            $prizeId = (int) $probability->prize_id;
            /** @var GachaPrize|null $prize */
            $prize = $prizesById->get($prizeId);
            $probabilityPpm = (int) $probability->probability_ppm;

            if (! $prize || ! $prize->is_active || $prize->won_count >= $prize->max_win_count) {
                $minimumGuaranteePpm += $probabilityPpm;
                continue;
            }

            if ($probabilityPpm > 0) {
                $entries[] = [
                    'prize_id' => $prizeId,
                    'is_minimum_guarantee' => false,
                    'probability_ppm' => $probabilityPpm,
                ];
            }
        }

        if ($minimumGuaranteePpm > 0) {
            $entries[] = [
                'prize_id' => null,
                'is_minimum_guarantee' => true,
                'probability_ppm' => $minimumGuaranteePpm,
            ];
        }

        $cursor = 0;
        $rangeEntries = [];

        // ppmを連続した整数範囲に変換し、0〜999999の乱数で1件だけ選べる形にする。
        foreach ($entries as $entry) {
            $start = $cursor;
            $cursor += $entry['probability_ppm'];
            $rangeEntries[] = new ProbabilityRangeEntry(
                start: $start,
                end: $cursor,
                prizeId: $entry['prize_id'],
                isMinimumGuarantee: $entry['is_minimum_guarantee'],
                probabilityPpm: $entry['probability_ppm'],
            );
        }

        if ($cursor !== ProbabilityValidator::TOTAL_PPM) {
            throw new LogicException('Probability range total must be exactly 1000000 ppm.');
        }

        return new ProbabilityRange($rangeEntries);
    }
}

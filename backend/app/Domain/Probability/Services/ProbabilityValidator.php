<?php

namespace App\Domain\Probability\Services;

use App\Domain\Probability\Enums\StageConditionType;
use App\Domain\Probability\Exceptions\ProbabilityValidationException;
use App\Models\Gacha;
use App\Models\GachaPrize;

class ProbabilityValidator
{
    public const TOTAL_PPM = 1_000_000;

    /**
     * @param list<array<string, mixed>> $stages
     * @return list<array<string, mixed>>
     */
    public function validateForPublish(Gacha $gacha, array $stages): array
    {
        $errors = [];

        if ($stages === []) {
            throw new ProbabilityValidationException(['At least one probability stage is required.']);
        }

        $prizeIds = GachaPrize::query()
            ->where('gacha_id', $gacha->id)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $validPrizeIds = array_flip($prizeIds);
        $normalized = [];

        foreach (array_values($stages) as $index => $stage) {
            $stageNumber = $index + 1;
            $probabilities = $stage['probabilities'] ?? null;

            if (! is_array($probabilities) || $probabilities === []) {
                $errors[] = "Stage {$stageNumber} must include probabilities.";
                continue;
            }

            $stageKey = (string) ($stage['stage_key'] ?? "stage_{$stageNumber}");
            $minDrawNumber = (int) ($stage['min_draw_number'] ?? 0);
            $maxDrawNumber = array_key_exists('max_draw_number', $stage) && $stage['max_draw_number'] !== null
                ? (int) $stage['max_draw_number']
                : null;

            if ($minDrawNumber < 1) {
                $errors[] = "{$stageKey}: min_draw_number must be greater than or equal to 1.";
            }

            if ($maxDrawNumber !== null && $maxDrawNumber < $minDrawNumber) {
                $errors[] = "{$stageKey}: max_draw_number must be null or greater than min_draw_number.";
            }

            $minimumGuaranteeCount = 0;
            $totalPpm = 0;
            $seenPrizeIds = [];
            $normalizedProbabilities = [];

            foreach (array_values($probabilities) as $probabilityIndex => $probability) {
                $probabilityPpm = (int) ($probability['probability_ppm'] ?? -1);
                $isMinimumGuarantee = (bool) ($probability['is_minimum_guarantee'] ?? false);
                $prizeId = isset($probability['prize_id']) ? (int) $probability['prize_id'] : null;

                if ($probabilityPpm < 0 || $probabilityPpm > self::TOTAL_PPM) {
                    $errors[] = "{$stageKey}: probability row ".($probabilityIndex + 1).' must be between 0 and 1000000 ppm.';
                }

                if ($isMinimumGuarantee) {
                    $minimumGuaranteeCount++;

                    if ($prizeId !== null) {
                        $errors[] = "{$stageKey}: minimum guarantee row must not have prize_id.";
                    }
                } else {
                    if ($prizeId === null) {
                        $errors[] = "{$stageKey}: prize probability row must have prize_id.";
                    } elseif (! isset($validPrizeIds[$prizeId])) {
                        $errors[] = "{$stageKey}: prize_id {$prizeId} does not belong to the gacha.";
                    } elseif (isset($seenPrizeIds[$prizeId])) {
                        $errors[] = "{$stageKey}: prize_id {$prizeId} is duplicated.";
                    }

                    if ($prizeId !== null) {
                        $seenPrizeIds[$prizeId] = true;
                    }
                }

                $totalPpm += $probabilityPpm;
                $normalizedProbabilities[] = [
                    'prize_id' => $isMinimumGuarantee ? null : $prizeId,
                    'is_minimum_guarantee' => $isMinimumGuarantee,
                    'probability_ppm' => $probabilityPpm,
                ];
            }

            if ($minimumGuaranteeCount !== 1) {
                $errors[] = "{$stageKey}: exactly one minimum guarantee row is required.";
            }

            if ($totalPpm !== self::TOTAL_PPM) {
                $errors[] = "{$stageKey}: probability total must be exactly 1000000 ppm.";
            }

            usort($normalizedProbabilities, function (array $a, array $b): int {
                if ($a['is_minimum_guarantee'] !== $b['is_minimum_guarantee']) {
                    return $a['is_minimum_guarantee'] ? 1 : -1;
                }

                return ($a['prize_id'] ?? PHP_INT_MAX) <=> ($b['prize_id'] ?? PHP_INT_MAX);
            });

            $normalized[] = [
                'stage_key' => $stageKey,
                'name' => (string) ($stage['name'] ?? $stageKey),
                'condition_type' => (string) ($stage['condition_type'] ?? StageConditionType::SoldCount->value),
                'min_draw_number' => $minDrawNumber,
                'max_draw_number' => $maxDrawNumber,
                'sort_order' => (int) ($stage['sort_order'] ?? $index),
                'probabilities' => $normalizedProbabilities,
            ];
        }

        $this->validateRanges($normalized, $gacha, $errors);

        if ($errors !== []) {
            throw new ProbabilityValidationException($errors);
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $normalized
     * @param list<string> $errors
     */
    private function validateRanges(array $normalized, Gacha $gacha, array &$errors): void
    {
        usort($normalized, fn (array $a, array $b): int => $a['min_draw_number'] <=> $b['min_draw_number']);

        $expectedMin = 1;

        foreach ($normalized as $stage) {
            if ($stage['condition_type'] !== StageConditionType::SoldCount->value) {
                $errors[] = "{$stage['stage_key']}: condition_type must be sold_count.";
            }

            if ($stage['min_draw_number'] !== $expectedMin) {
                $errors[] = "{$stage['stage_key']}: stage ranges must not have gaps or overlaps.";
            }

            $expectedMin = $stage['max_draw_number'] === null
                ? $gacha->total_count + 1
                : $stage['max_draw_number'] + 1;
        }

        if ($expectedMin <= $gacha->total_count) {
            $errors[] = 'Probability stage ranges must cover the gacha total_count.';
        }
    }
}

<?php

namespace App\Domain\MemberRank\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;

final class V2MemberRankGachaCondition
{
    public static function validate(array $input): array
    {
        $mode = $input['member_rank_mode'] ?? 'none';
        $level = $input['member_rank_level'] ?? null;
        if (! in_array($mode, ['none', 'at_least', 'only', 'at_most'], true)
            || ($mode === 'none' ? $level !== null : (! is_int($level) || $level < 1 || $level > 7))
            || (array_key_exists('member_rank_mode', $input) && $input['member_rank_mode'] === null)) {
            throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The member rank condition is invalid.');
        }

        return ['member_rank_mode' => $mode, 'member_rank_level' => $level];
    }

    public static function matches(object $version, array $projection): bool
    {
        if (! $projection['enabled']) {
            return true;
        }
        $level = $version->member_rank_level ?? null;

        return match ($version->member_rank_mode ?? 'none') {
            'none' => true,
            'at_least' => $level !== null && $projection['level'] >= $level,
            'only' => $level !== null && $projection['level'] === (int) $level,
            'at_most' => $level !== null && $projection['level'] <= $level,
            default => false,
        };
    }

    public static function presentation(object $version): array
    {
        return [
            'mode' => $version->member_rank_mode ?? 'none',
            'rank' => self::rank(isset($version->member_rank_level) ? (int) $version->member_rank_level : null),
        ];
    }

    public static function rank(?int $level): ?array
    {
        if ($level === null) {
            return null;
        }
        if ($level < 1 || $level > 7) {
            throw new \LogicException('Invalid member rank snapshot.');
        }

        return [
            'code' => V2MemberRankSettingsService::CODES[$level - 1],
            'name' => ['ビギナー', 'ブロンズ', 'シルバー', 'ゴールド', 'プラチナ', 'ダイヤモンド', 'VIP'][$level - 1],
            'level' => $level,
        ];
    }
}

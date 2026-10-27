<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Payment\V2\Services\V2PaymentCommercialQuery;
use App\Domain\Catalog\Exceptions\V2CatalogException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2GachaPurchaseCondition
{
    public const FIELDS = ['purchase_count_mode', 'purchase_count_value', 'purchase_amount_mode', 'purchase_amount_value'];

    public static function validate(array $input): array
    {
        $result = [];
        foreach (['purchase_count' => 1000000, 'purchase_amount' => 9007199254740991] as $field => $maximum) {
            $mode = array_key_exists($field.'_mode', $input) ? $input[$field.'_mode'] : 'none';
            $value = $input[$field.'_value'] ?? null;
            if (! in_array($mode, ['none', 'eq', 'gte', 'lt'], true)
                || ($mode === 'none' ? $value !== null
                    : (! is_int($value) || $value < ($mode === 'eq' ? 0 : 1) || $value > $maximum))) {
                throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The purchase condition is invalid.');
            }
            $result[$field.'_mode'] = $mode;
            $result[$field.'_value'] = $value;
        }
        foreach ([['purchase_count', 'purchase_amount'], ['purchase_amount', 'purchase_count']] as [$zero, $positive]) {
            if ((($result[$zero.'_mode'] === 'eq' && $result[$zero.'_value'] === 0)
                    || ($result[$zero.'_mode'] === 'lt' && $result[$zero.'_value'] === 1))
                && in_array($result[$positive.'_mode'], ['eq', 'gte'], true) && $result[$positive.'_value'] >= 1) {
                throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The purchase conditions contradict each other.');
            }
        }

        return $result;
    }

    public static function values(object $version): array
    {
        return [
            'purchase_count_mode' => $version->purchase_count_mode ?? 'none',
            'purchase_count_value' => isset($version->purchase_count_value) ? (int) $version->purchase_count_value : null,
            'purchase_amount_mode' => $version->purchase_amount_mode ?? 'none',
            'purchase_amount_value' => isset($version->purchase_amount_value) ? (int) $version->purchase_amount_value : null,
        ];
    }

    public static function aggregate(int $userId): array
    {
        $aggregate = DB::table('payments as purchase')
            ->tap(fn (Builder $query) => V2PaymentCommercialQuery::apply($query, 'purchase.id'))
            ->where('purchase.user_id', $userId)->where('purchase.status', 'succeeded')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('payment_adjustments as adjustment')
                    ->whereColumn('adjustment.payment_id', 'purchase.id')
                    ->whereIn('adjustment.type', ['refund', 'chargeback'])
                    ->where('adjustment.status', 'succeeded');
            })->selectRaw('COUNT(*) AS purchase_count, COALESCE(SUM(purchase.amount), 0) AS purchase_amount')->first();

        return ['purchase_count' => (int) $aggregate->purchase_count, 'purchase_amount' => (int) $aggregate->purchase_amount];
    }

    public static function reason(object $version, array $aggregate): ?string
    {
        foreach (['purchase_count', 'purchase_amount'] as $field) {
            $value = $version->{$field.'_value'} ?? null;
            $matches = match ($version->{$field.'_mode'} ?? 'none') {
                'none' => true,
                'eq' => $value !== null && $aggregate[$field] === (int) $value,
                'gte' => $value !== null && $aggregate[$field] >= $value,
                'lt' => $value !== null && $aggregate[$field] < $value,
                default => false,
            };
            if (! $matches) {
                return $field.'_not_eligible';
            }
        }

        return null;
    }

    public static function conditions(object $version): array
    {
        $conditions = [
            'audience' => $version->audience_code,
            'member_rank' => ($version->member_rank_mode ?? 'none') === 'none' ? null
                : \App\Domain\MemberRank\Services\V2MemberRankGachaCondition::presentation($version),
        ];
        foreach (['purchase_count', 'purchase_amount'] as $field) {
            $mode = $version->{$field.'_mode'} ?? 'none';
            $conditions[$field] = $mode === 'none' ? null : ['operator' => $mode, 'value' => (int) $version->{$field.'_value'}];
        }

        return $conditions;
    }

    public static function progress(object $version, ?array $aggregate): ?array
    {
        if ($aggregate === null || (($version->purchase_count_mode ?? 'none') === 'none'
                && ($version->purchase_amount_mode ?? 'none') === 'none')) {
            return null;
        }
        $progress = $aggregate;
        foreach (['purchase_count', 'purchase_amount'] as $field) {
            $progress[$field.'_remaining'] = ($version->{$field.'_mode'} ?? 'none') === 'gte'
                && $aggregate[$field] < $version->{$field.'_value'}
                ? (int) $version->{$field.'_value'} - $aggregate[$field] : null;
        }

        return $progress;
    }
}

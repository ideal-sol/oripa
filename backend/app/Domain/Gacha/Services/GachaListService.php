<?php

namespace App\Domain\Gacha\Services;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Models\Gacha;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GachaListService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Gacha::query()
            ->with([
                'category',
                'tags' => fn ($query) => $query->where('is_active', true),
            ])
            ->where(function ($query): void {
                $query
                    ->whereIn('status', [GachaStatus::Active->value, GachaStatus::SoldOut->value])
                    ->orWhereColumn('sold_count', '>=', 'total_count');
            })
            ->orderByRaw("CASE WHEN status = ? AND sold_count < total_count THEN 0 ELSE 1 END", [GachaStatus::Active->value])
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}

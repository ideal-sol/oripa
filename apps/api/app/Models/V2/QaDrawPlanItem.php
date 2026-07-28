<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class QaDrawPlanItem extends Model
{
    protected $table = 'qa_draw_plan_items';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->public_id ??= (string) Str::uuid7();
        });
        static::deleting(static function (): never {
            throw new \LogicException('V2 QA Draw Plan Item cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'quantity' => 'integer',
            'consumed_count' => 'integer',
        ];
    }
}

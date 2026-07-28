<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class QaDrawPlan extends Model
{
    protected $table = 'qa_draw_plans';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $plan): void {
            $plan->public_id ??= (string) Str::uuid7();
        });
        static::deleting(static function (): never {
            throw new \LogicException('V2 QA Draw Plan cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}

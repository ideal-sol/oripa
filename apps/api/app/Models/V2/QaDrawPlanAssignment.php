<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class QaDrawPlanAssignment extends Model
{
    protected $table = 'qa_draw_plan_assignments';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            $assignment->public_id ??= (string) Str::uuid7();
        });
        static::deleting(static function (): never {
            throw new \LogicException('V2 QA Draw Plan Assignment cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'unassigned_at' => 'immutable_datetime',
        ];
    }
}

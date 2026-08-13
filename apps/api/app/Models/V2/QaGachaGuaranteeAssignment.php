<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class QaGachaGuaranteeAssignment extends Model
{
    protected $table = 'qa_gacha_guarantee_assignments';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            $assignment->public_id ??= (string) Str::uuid7();
        });
        static::deleting(static function (): never {
            throw new \LogicException('V2 QA Gacha Guarantee Assignment cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'unassigned_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class QaDrawExecution extends Model
{
    public $timestamps = false;

    protected $table = 'qa_draw_executions';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $execution): void {
            $execution->public_id ??= (string) Str::uuid7();
        });
        static::updating(static function (): never {
            throw new \LogicException('V2 QA Draw Execution is immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('V2 QA Draw Execution is immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'executed_count' => 'integer',
            'executed_at' => 'immutable_datetime',
            'metadata_redacted' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}

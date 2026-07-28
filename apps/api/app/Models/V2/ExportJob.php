<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ExportJob extends Model
{
    protected $table = 'export_jobs';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $job): void {
            $job->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'period_date' => 'immutable_date',
            'data_cutoff_at' => 'immutable_datetime',
            'row_count' => 'integer',
            'byte_size' => 'integer',
            'attempts' => 'integer',
            'locked_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

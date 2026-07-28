<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ContentVersion extends Model
{
    protected $table = 'content_versions';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'is_important' => 'boolean',
            'publish_start_at' => 'immutable_datetime',
            'publish_end_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}

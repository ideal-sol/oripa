<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ContentBanner extends Model
{
    protected $table = 'content_banners';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::uuid7();
        });
    }
}

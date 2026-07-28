<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ContentStaticPage extends Model
{
    protected $table = 'content_static_pages';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::uuid7();
        });
    }
}

<?php

namespace App\Models;

use App\Domain\Content\Enums\PublishStatus;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'thumbnail_url',
        'show_on_top_slider',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'published_at' => 'datetime',
            'show_on_top_slider' => 'boolean',
        ];
    }
}

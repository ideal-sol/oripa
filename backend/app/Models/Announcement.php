<?php

namespace App\Models;

use App\Domain\Content\Enums\AnnouncementCategory;
use App\Domain\Content\Enums\PublishStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'category',
        'thumbnail_url',
        'show_on_top_slider',
        'status',
        'published_at',
        'published_until',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'category' => AnnouncementCategory::class,
            'published_at' => 'datetime',
            'published_until' => 'datetime',
            'show_on_top_slider' => 'boolean',
        ];
    }

    public function scopePubliclyVisible(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', PublishStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at)
            ->where(function (Builder $query) use ($at): void {
                $query
                    ->whereNull('published_until')
                    ->orWhere('published_until', '>', $at);
            });
    }

    public function scopePublicNoticeListing(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $query
            ->publiclyVisible($at)
            ->where('category', AnnouncementCategory::Notice->value);
    }
}

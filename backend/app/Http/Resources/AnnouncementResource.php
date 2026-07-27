<?php

namespace App\Http\Resources;

use App\Domain\Content\Enums\AnnouncementCategory;
use App\Domain\Content\Services\AnnouncementContentSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->category instanceof \BackedEnum ? $this->category->value : $this->category;
        $sanitizer = app(AnnouncementContentSanitizer::class);
        $body = $sanitizer->sanitizeForStorage($this->body);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $body,
            'body_html' => $sanitizer->render($body),
            'category' => $category,
            'thumbnail_url' => $this->thumbnail_url,
            'show_on_top_slider' => (bool) $this->show_on_top_slider,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_until' => $this->published_until?->toIso8601String(),
            'robots' => $category === AnnouncementCategory::LandingPage->value
                ? 'noindex, nofollow, noarchive'
                : 'index, follow',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Resources\StaticPageResource;
use App\Models\StaticPage;
use Illuminate\Routing\Controller;

class StaticPageController extends Controller
{
    public function show(string $slug): StaticPageResource
    {
        $page = StaticPage::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->firstOrFail();

        return new StaticPageResource($page);
    }
}

<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AnnouncementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AnnouncementResource::collection(
            Announcement::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->orderByDesc('published_at')
                ->limit(5)
                ->get()
        );
    }

    public function show(Request $request, Announcement $announcement): AnnouncementResource
    {
        abort_unless(
            ($announcement->status instanceof \BackedEnum ? $announcement->status->value : $announcement->status) === 'published'
                && $announcement->published_at !== null
                && $announcement->published_at->lessThanOrEqualTo(now()),
            404,
        );

        return new AnnouncementResource($announcement);
    }
}

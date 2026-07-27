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
                ->publicNoticeListing()
                ->orderByDesc('published_at')
                ->limit(5)
                ->get()
        );
    }

    public function show(Request $request, Announcement $announcement): AnnouncementResource
    {
        $announcement = Announcement::query()
            ->publiclyVisible()
            ->whereKey($announcement->getKey())
            ->firstOrFail();

        return new AnnouncementResource($announcement);
    }
}

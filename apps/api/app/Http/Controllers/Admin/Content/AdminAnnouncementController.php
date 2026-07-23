<?php

namespace App\Http\Controllers\Admin\Content;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Http\Requests\Admin\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminAnnouncementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Announcement::query()->orderByDesc('published_at')->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return AnnouncementResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function store(StoreAnnouncementRequest $request, AuditLogService $auditLogService): AnnouncementResource
    {
        $payload = $this->payload($request->validated());
        $announcement = Announcement::query()->create($payload);

        $auditLogService->record(
            action: 'admin.announcement.created',
            adminUser: $request->user(),
            auditable: $announcement,
            request: $request,
            metadata: ['attributes' => $payload],
        );

        return new AnnouncementResource($announcement);
    }

    public function show(Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($announcement);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement, AuditLogService $auditLogService): AnnouncementResource
    {
        $payload = $this->payload($request->validated());
        $before = $announcement->only(array_keys($payload));

        $announcement->fill($payload)->save();

        $auditLogService->record(
            action: 'admin.announcement.updated',
            adminUser: $request->user(),
            auditable: $announcement,
            request: $request,
            metadata: ['before' => $before, 'after' => $announcement->only(array_keys($payload))],
        );

        return new AnnouncementResource($announcement->refresh());
    }
    private function payload(array $payload): array
    {
        if (($payload['status'] ?? null) === 'published' && empty($payload['published_at'])) {
            $payload['published_at'] = now();
        }

        if (($payload['status'] ?? null) !== 'published' && ! array_key_exists('published_at', $payload)) {
            $payload['published_at'] = null;
        }

        return $payload;
    }
}

<?php

namespace App\Http\Controllers\Admin\Content;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Content\Enums\AnnouncementCategory;
use App\Domain\Content\Services\AnnouncementContentSanitizer;
use App\Http\Requests\Admin\PreviewAnnouncementRequest;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Http\Requests\Admin\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AdminAnnouncementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Announcement::query()->orderByDesc('published_at')->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('category')) {
            $category = $request->validate([
                'category' => ['required', Rule::enum(AnnouncementCategory::class)],
            ])['category'];

            $query->where('category', $category);
        }

        return AnnouncementResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function store(
        StoreAnnouncementRequest $request,
        AuditLogService $auditLogService,
        AnnouncementContentSanitizer $sanitizer,
    ): AnnouncementResource
    {
        $payload = $this->payload($request->validated(), $sanitizer);
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

    public function update(
        UpdateAnnouncementRequest $request,
        Announcement $announcement,
        AuditLogService $auditLogService,
        AnnouncementContentSanitizer $sanitizer,
    ): AnnouncementResource
    {
        $payload = $this->payload($request->validated(), $sanitizer);
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

    public function preview(
        PreviewAnnouncementRequest $request,
        AnnouncementContentSanitizer $sanitizer,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'body_html' => $sanitizer->render($request->validated('body')),
            ],
        ]);
    }

    private function payload(array $payload, AnnouncementContentSanitizer $sanitizer): array
    {
        $payload['body'] = $sanitizer->sanitizeForStorage($payload['body']);

        if (($payload['category'] ?? null) === AnnouncementCategory::LandingPage->value) {
            $payload['show_on_top_slider'] = false;
        }

        if (($payload['status'] ?? null) === 'published' && empty($payload['published_at'])) {
            $payload['published_at'] = now();
        }

        if (($payload['status'] ?? null) !== 'published' && ! array_key_exists('published_at', $payload)) {
            $payload['published_at'] = null;
        }

        return $payload;
    }
}

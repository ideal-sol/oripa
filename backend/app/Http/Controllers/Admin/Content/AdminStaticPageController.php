<?php

namespace App\Http\Controllers\Admin\Content;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\UpdateStaticPageRequest;
use App\Http\Resources\StaticPageResource;
use App\Models\StaticPage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminStaticPageController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StaticPageResource::collection(
            StaticPage::query()
                ->whereIn('slug', ['terms', 'privacy', 'commercial-law'])
                ->orderByRaw("CASE slug WHEN 'terms' THEN 1 WHEN 'privacy' THEN 2 WHEN 'commercial-law' THEN 3 ELSE 4 END")
                ->get()
        );
    }

    public function show(StaticPage $staticPage): StaticPageResource
    {
        abort_unless(in_array($staticPage->slug, ['terms', 'privacy', 'commercial-law'], true), 404);

        return new StaticPageResource($staticPage);
    }

    public function update(UpdateStaticPageRequest $request, StaticPage $staticPage, AuditLogService $auditLogService): StaticPageResource
    {
        abort_unless(in_array($staticPage->slug, ['terms', 'privacy', 'commercial-law'], true), 404);

        $payload = $request->validated();
        $before = $staticPage->only(array_keys($payload));

        $staticPage->fill([
            ...$payload,
            'status' => 'published',
            'published_at' => $staticPage->published_at ?? now(),
        ])->save();

        $auditLogService->record(
            action: 'admin.static_page.updated',
            adminUser: $request->user(),
            auditable: $staticPage,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $staticPage->only(array_keys($payload)),
            ],
        );

        return new StaticPageResource($staticPage->refresh());
    }
}

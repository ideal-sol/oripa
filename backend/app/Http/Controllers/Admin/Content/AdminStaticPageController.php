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
    private const EDITABLE_SLUGS = [
        'terms',
        'point-terms',
        'privacy',
        'commercial-law',
        'antique-dealer',
        'return-policy',
        'shipping-policy',
        'oripa-notice',
        'contact-info',
    ];

    public function index(): AnonymousResourceCollection
    {
        return StaticPageResource::collection(
            StaticPage::query()
                ->whereIn('slug', self::EDITABLE_SLUGS)
                ->orderByRaw("CASE slug WHEN 'terms' THEN 1 WHEN 'point-terms' THEN 2 WHEN 'privacy' THEN 3 WHEN 'commercial-law' THEN 4 WHEN 'antique-dealer' THEN 5 WHEN 'return-policy' THEN 6 WHEN 'shipping-policy' THEN 7 WHEN 'oripa-notice' THEN 8 WHEN 'contact-info' THEN 9 ELSE 10 END")
                ->get()
        );
    }

    public function show(StaticPage $staticPage): StaticPageResource
    {
        abort_unless(in_array($staticPage->slug, self::EDITABLE_SLUGS, true), 404);

        return new StaticPageResource($staticPage);
    }

    public function update(UpdateStaticPageRequest $request, StaticPage $staticPage, AuditLogService $auditLogService): StaticPageResource
    {
        abort_unless(in_array($staticPage->slug, self::EDITABLE_SLUGS, true), 404);

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

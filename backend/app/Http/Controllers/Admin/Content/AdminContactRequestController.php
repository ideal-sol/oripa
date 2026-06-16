<?php

namespace App\Http\Controllers\Admin\Content;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\ReplyContactRequest;
use App\Http\Resources\ContactRequestResource;
use App\Models\ContactRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;

class AdminContactRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ContactRequest::query()->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('email')) {
            $query->where('email', 'ilike', '%'.$request->string('email')->toString().'%');
        }

        return ContactRequestResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(ContactRequest $contactRequest): ContactRequestResource
    {
        return new ContactRequestResource($contactRequest);
    }

    public function update(ReplyContactRequest $request, ContactRequest $contactRequest, AuditLogService $auditLogService): ContactRequestResource
    {
        $payload = $request->validated();
        $before = $contactRequest->only(['status', 'reply_body', 'replied_by_admin_user_id', 'replied_at']);
        $previousReplyBody = $contactRequest->reply_body;

        if (($payload['reply_body'] ?? null) !== null && trim((string) $payload['reply_body']) !== '') {
            $payload['status'] = 'replied';
            $payload['replied_by_admin_user_id'] = $request->user()->id;
            $payload['replied_at'] = now();
        }

        if (($payload['status'] ?? null) !== 'replied' && empty($payload['reply_body'])) {
            $payload['reply_body'] = null;
            $payload['replied_by_admin_user_id'] = null;
            $payload['replied_at'] = null;
        }

        $contactRequest->fill($payload)->save();

        if (
            ! empty($contactRequest->reply_body)
            && $contactRequest->reply_body !== $previousReplyBody
        ) {
            Mail::raw((string) $contactRequest->reply_body, function ($message) use ($contactRequest): void {
                $message
                    ->to($contactRequest->email, $contactRequest->name)
                    ->subject('お問い合わせへのご返信');
            });
        }

        $auditLogService->record(
            action: 'admin.contact_request.updated',
            adminUser: $request->user(),
            auditable: $contactRequest,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $contactRequest->only(['status', 'reply_body', 'replied_by_admin_user_id', 'replied_at']),
            ],
        );

        return new ContactRequestResource($contactRequest->refresh());
    }
}

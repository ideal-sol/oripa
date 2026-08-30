<?php

namespace App\Domain\Mail\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\ContentContact\Services\V2ContentHtmlSanitizer;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Mail\Exceptions\V2MailTemplateException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\MailTemplate;
use Illuminate\Support\Facades\DB;
use Normalizer;

final class V2MailTemplateService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
        private readonly V2ContentHtmlSanitizer $sanitizer,
        private readonly V2TemplateVariableRenderer $renderer,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit
    ) {
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function templates(V2AdminAuthorizationContext $context): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContent);
        $rows = MailTemplate::query()->get()->keyBy('template_key');
        $items = [];
        foreach ($this->catalog() as $key => $label) {
            $row = $rows->get($key);
            if (! $row instanceof MailTemplate) {
                throw $this->unavailable();
            }
            $items[] = $this->result($row, $label);
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function template(V2AdminAuthorizationContext $context, string $key): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContent);

        return $this->result($this->find($key), $this->catalog()[$key]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(
        V2AdminAuthorizationContext $context,
        string $key,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorizer->authorizePermission($context, V2Permission::ManageContent);
        $payload = $this->input($input);

        try {
            return DB::transaction(function () use (
                $admin,
                $context,
                $idempotencyKey,
                $key,
                $payload
            ): array {
                $claim = $this->idempotency->claim(
                    'mail.template.update',
                    'admin',
                    $admin->public_id,
                    $idempotencyKey,
                    ['template_key' => $key, ...$payload]
                );
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response)) {
                        throw $this->unavailable();
                    }

                    return [...$response, 'idempotent_replay' => true];
                }
                $template = MailTemplate::query()
                    ->where('template_key', $key)
                    ->lockForUpdate()
                    ->first();
                if (! $template instanceof MailTemplate || ! isset($this->catalog()[$key])) {
                    throw $this->notFound();
                }
                if ($template->revision !== $payload['expected_revision']) {
                    throw new V2MailTemplateException(
                        'MAIL_TEMPLATE_REVISION_CONFLICT',
                        409,
                        'The Mail Template changed before this request was saved.'
                    );
                }
                $template->forceFill([
                    'subject_template' => $payload['subject'],
                    'body_html' => $payload['body_html'],
                    'revision' => $template->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ])->save();
                $result = $this->result($template->refresh(), $this->catalog()[$key]);
                $this->audit->record('mail.template.updated', [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin',
                    'actor_public_id' => $admin->public_id,
                    'actor_role' => $admin->role->value,
                    'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'mail_template',
                    'target_public_id' => $template->public_id,
                    'before' => ['revision' => $payload['expected_revision']],
                    'after' => ['revision' => $template->revision],
                    'metadata' => ['template_key' => $key],
                ]);
                $this->idempotency->complete(
                    $claim->record,
                    'mail_template',
                    $template->public_id,
                    $result
                );

                return [...$result, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw match ($exception->getMessage()) {
                'IDEMPOTENCY_KEY_REUSED' => new V2MailTemplateException(
                    'IDEMPOTENCY_KEY_REUSED', 409, 'The Idempotency Key was reused.'
                ),
                'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2MailTemplateException(
                    'IDEMPOTENCY_REQUEST_IN_PROGRESS', 409,
                    'The Mail Template request is already in progress.', true
                ),
                default => $this->invalid(),
            };
        }
    }

    /** @param array<string, mixed> $input @return array{body_html: string} */
    public function preview(
        V2AdminAuthorizationContext $context,
        string $key,
        array $input
    ): array {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContent);
        if (! isset($this->catalog()[$key]) || array_keys($input) !== ['body_html']) {
            throw $this->invalid();
        }
        $body = $this->body($input['body_html'] ?? null);
        $values = config('v2_mail_templates.preview_values', []);
        if (! is_array($values)) {
            throw $this->unavailable();
        }

        return ['body_html' => $this->renderer->html($body, $values)];
    }

    /** @param array<string, mixed> $input @return array{subject: string, body_html: string, expected_revision: int} */
    private function input(array $input): array
    {
        if (array_diff(array_keys($input), ['subject', 'body_html', 'expected_revision']) !== []) {
            throw $this->invalid();
        }
        $revision = $input['expected_revision'] ?? null;
        if (! is_int($revision) || $revision < 1) {
            throw $this->invalid();
        }

        return [
            'subject' => $this->subject($input['subject'] ?? null),
            'body_html' => $this->body($input['body_html'] ?? null),
            'expected_revision' => $revision,
        ];
    }

    private function subject(mixed $value): string
    {
        if (! is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw $this->invalid();
        }
        $normalized = Normalizer::normalize(trim($value), Normalizer::FORM_C);
        if (! is_string($normalized) || mb_strlen($normalized) > 191 || ! $this->semantic($normalized)) {
            throw $this->invalid();
        }

        return $normalized;
    }

    private function body(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 100_000) {
            throw $this->invalid();
        }
        $body = $this->sanitizer->sanitize($value);
        if (! $this->semantic(html_entity_decode(strip_tags($body), ENT_HTML5, 'UTF-8'))
            && preg_match('/<img\b/i', $body) !== 1) {
            throw $this->invalid();
        }

        return $body;
    }

    private function semantic(string $value): bool
    {
        $compact = preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $value);

        return is_string($compact) && $compact !== '';
    }

    private function find(string $key): MailTemplate
    {
        if (! isset($this->catalog()[$key])) {
            throw $this->notFound();
        }
        $template = MailTemplate::query()->where('template_key', $key)->first();
        if (! $template instanceof MailTemplate) {
            throw $this->unavailable();
        }

        return $template;
    }

    /** @return array<string, string> */
    private function catalog(): array
    {
        $catalog = config('v2_mail_templates.templates', []);
        if (! is_array($catalog) || count($catalog) !== 11) {
            throw $this->unavailable();
        }

        return $catalog;
    }

    /** @return list<array{key: string, label: string, token: string}> */
    private function variables(): array
    {
        $configured = config('v2_mail_templates.variables', []);
        if (! is_array($configured)) {
            throw $this->unavailable();
        }

        return collect($configured)->map(
            static fn (mixed $label, mixed $key): array => [
                'key' => (string) $key,
                'label' => (string) $label,
                'token' => '{{'.(string) $key.'}}',
            ]
        )->values()->all();
    }

    /** @return array<string, mixed> */
    private function result(MailTemplate $template, string $label): array
    {
        return [
            'key' => $template->template_key,
            'label' => $label,
            'subject' => $template->subject_template,
            'body_html' => $template->body_html,
            'revision' => $template->revision,
            'variables' => $this->variables(),
            'updated_at' => $template->updated_at->toIso8601String(),
        ];
    }

    private function invalid(): V2MailTemplateException
    {
        return new V2MailTemplateException(
            'MAIL_TEMPLATE_INVALID', 422, 'The Mail Template request is invalid.'
        );
    }

    private function notFound(): V2MailTemplateException
    {
        return new V2MailTemplateException(
            'MAIL_TEMPLATE_NOT_FOUND', 404, 'The Mail Template was not found.'
        );
    }

    private function unavailable(): V2MailTemplateException
    {
        return new V2MailTemplateException(
            'MAIL_TEMPLATE_UNAVAILABLE', 503, 'The Mail Template is unavailable.', true
        );
    }
}

<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AgencyException;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Reporting\Services\V2ReportingCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class V2AgencyService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2ReportingCursor $cursor,
        private readonly V2AgencyIdentifierGenerator $identifiers,
        private readonly V2AgencyPasswordPolicy $passwords,
        private readonly V2TemplateMailDeliveryService $mail
    ) {
    }

    public function listing(V2AdminAuthorizationContext $context, ?string $cursor, int $limit): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadAgency);
        if ($limit < 1 || $limit > 100) {
            throw $this->invalid();
        }
        $after = $this->cursor->decode($cursor);
        $query = DB::table('agencies')->orderByDesc('id');
        if ($after !== null) {
            $query->where('id', '<', $after);
        }
        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);

        return [
            'items' => $page->map(fn (object $row): array => $this->resource($row))->all(),
            'next_cursor' => $rows->count() > $limit ? $this->cursor->encode((int) $page->last()->id) : null,
        ];
    }

    public function detail(V2AdminAuthorizationContext $context, string $publicId): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadAgency);

        return ['data' => $this->resource($this->row($publicId))];
    }

    public function issue(V2AdminAuthorizationContext $context): array
    {
        $this->authorize($context, 'issuance');
        $values = [
            'login_id' => $this->identifiers->loginId(),
            'advertising_code' => $this->identifiers->advertisingCode(),
        ];

        return [...$values, 'issuance_token' => Crypt::encryptString(json_encode([
            ...$values, 'admin_id' => $context->adminPublicId, 'expires_at' => now()->addMinutes(30)->timestamp,
        ], JSON_THROW_ON_ERROR))];
    }

    public function mutate(
        V2AdminAuthorizationContext $context,
        string $operation,
        ?string $publicId,
        #[SensitiveParameter] array $input,
        string $idempotencyKey
    ): array {
        if (! in_array($operation, ['create', 'update', 'suspend', 'reactivate', 'password-reset', 'login-information-reissue'], true)) {
            throw $this->invalid();
        }
        $this->authorize($context, $operation);
        $payload = $this->validate($operation, $input);
        $password = $payload['password'] ?? null;
        unset($payload['password']);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw $this->invalid();
        }
        try {
            return DB::transaction(function () use ($context, $operation, $publicId, $payload, $password, $idempotencyKey): array {
                $claim = $this->idempotency->claim('agency.'.$operation, 'admin', $context->adminPublicId,
                    $idempotencyKey, ['agency_id' => $publicId, ...$payload]);
                if ($claim->replay) {
                    return [...$claim->record->response_data, 'idempotent_replay' => true];
                }
                if ($operation === 'create') {
                    $agency = $this->create($context, $payload, $password);
                } else {
                    $agency = $this->row($publicId, true);
                    if ((int) $agency->revision !== $payload['expected_revision']) {
                        throw new V2AgencyException('AGENCY_REVISION_CONFLICT', 409, 'The Agency was updated by another request.');
                    }
                    $changes = match ($operation) {
                        'update' => $this->companyFields($payload) + ['login_id' => $payload['login_id']],
                        'suspend' => ['status' => 'suspended'],
                        'reactivate' => ['status' => 'active'],
                        default => ['password_hash' => $this->passwords->hash($password)],
                    };
                    DB::table('agencies')->where('id', $agency->id)->update([
                        ...$changes, 'revision' => $agency->revision + 1, 'updated_at' => now()->startOfSecond(),
                    ]);
                    $agency = $this->row($publicId);
                }
                $data = $this->resource($agency);
                $this->audit->record('agency.'.$operation, [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin', 'actor_public_id' => $context->adminPublicId,
                    'actor_role' => $context->role->value, 'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'agency', 'target_public_id' => $agency->public_id,
                    'outcome' => 'success', 'after' => ['revision' => $data['revision'], 'status' => $data['status']],
                ]);
                $template = match ($operation) {
                    'create' => 'agency_account_created',
                    'password-reset' => 'agency_password_changed',
                    'login-information-reissue' => 'agency_login_information_reissued',
                    default => null,
                };
                if ($template !== null) {
                    $this->mail->scheduleAgency($template, $agency->public_id, $data['revision'], $agency->email, [
                        'agency_company_name' => $agency->company_name,
                        'agency_contact_name' => $agency->contact_name,
                        'agency_login_id' => $agency->login_id,
                        'agency_password' => $operation === 'password-reset' ? '' : $password,
                        'agency_advertising_code' => $data['advertising_code'],
                        'agency_login_url' => (string) config('v2_agency.login_url'),
                        'agency_changed_at' => $data['updated_at'],
                    ]);
                }
                $response = ['data' => $data, 'idempotent_replay' => false];
                $this->idempotency->complete($claim->record, 'agency', $agency->public_id, $response);

                return $response;
            }, 3);
        } catch (V2PointException $exception) {
            throw new V2AgencyException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED' ? 'IDEMPOTENCY_KEY_REUSED' : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409, 'The Agency request conflicts with an existing operation.'
            );
        } catch (QueryException $exception) {
            throw new V2AgencyException(
                $exception->getCode() === '23505' ? 'AGENCY_IDENTITY_CONFLICT' : 'AGENCY_SAVE_FAILED',
                $exception->getCode() === '23505' ? 409 : 503,
                'The Agency could not be saved.'
            );
        }
    }

    private function create(V2AdminAuthorizationContext $context, array $payload, #[SensitiveParameter] string $password): object
    {
        try {
            $issued = json_decode(Crypt::decryptString($payload['issuance_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $this->invalid();
        }
        if (($issued['admin_id'] ?? null) !== $context->adminPublicId || ($issued['expires_at'] ?? 0) < now()->timestamp) {
            throw $this->invalid();
        }
        $loginId = $issued['login_id'];
        $code = $issued['advertising_code'];
        $hash = $this->passwords->hash($password);
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($payload, $hash, $loginId, $code): object {
                    $publicId = (string) Str::uuid7();
                    $internalId = DB::table('agencies')->insertGetId([
                        ...$this->companyFields($payload), 'public_id' => $publicId,
                        'login_id' => $loginId, 'password_hash' => $hash,
                        'status' => 'active', 'revision' => 1,
                        'created_at' => now()->startOfSecond(), 'updated_at' => now()->startOfSecond(),
                    ]);
                    DB::table('agency_advertising_codes')->insert([
                        'agency_id' => $internalId, 'code' => $code, 'created_at' => now()->startOfSecond(),
                    ]);

                    return $this->row($publicId);
                });
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23505') {
                    throw $exception;
                }
                $constraint = $exception->errorInfo[2] ?? '';
                if (str_contains($constraint, 'agencies_login_id_unique')) {
                    $loginId = $this->identifiers->loginId();
                } elseif (str_contains($constraint, 'agency_advertising_codes_code_unique')) {
                    $code = $this->identifiers->advertisingCode();
                } else {
                    throw $exception;
                }
            }
        }
        throw new V2AgencyException('AGENCY_IDENTIFIER_EXHAUSTED', 409, 'Please issue new Agency identifiers.');
    }

    private function validate(string $operation, #[SensitiveParameter] array $input): array
    {
        $rules = [];
        if (in_array($operation, ['create', 'update'], true)) {
            $rules = [
                'company_name' => ['required', 'string', 'max:200'],
                'contact_name' => ['required', 'string', 'max:200'],
                'phone' => ['required', 'string', 'max:40', 'regex:/\A[0-9+() .-]+\z/'],
                'email' => ['required', 'string', 'email:rfc', 'max:320'],
                'address' => ['required', 'string', 'max:1000'],
                'memo' => ['nullable', 'string', 'max:5000'],
            ];
        }
        if ($operation === 'create') {
            $rules['issuance_token'] = ['required', 'string', 'max:4096'];
        } else {
            $rules['expected_revision'] = ['required', 'integer', 'min:1'];
            if (! is_int($input['expected_revision'] ?? null)) {
                throw $this->invalid();
            }
        }
        if ($operation === 'update') {
            $rules['login_id'] = ['required', 'string', 'regex:/\A[0-9]{6}\z/'];
        }
        if (in_array($operation, ['create', 'password-reset', 'login-information-reissue'], true)) {
            $rules['password'] = ['required', 'string'];
            if (! is_string($input['password'] ?? null) || ! $this->passwords->isAllowed($input['password'])) {
                throw $this->invalid();
            }
        }
        if (array_diff(array_keys($input), array_keys($rules)) !== [] || Validator::make($input, $rules)->fails()) {
            throw $this->invalid();
        }

        return $input;
    }

    private function companyFields(array $payload): array
    {
        $fields = [];
        foreach (['company_name', 'contact_name', 'phone', 'email', 'address'] as $field) {
            $fields[$field] = trim($payload[$field]);
            if ($fields[$field] === '' || preg_match('/[\x00-\x1F\x7F]/u', $fields[$field])) {
                throw $this->invalid();
            }
        }
        $fields['normalized_email'] = mb_strtolower($fields['email'], 'UTF-8');
        $fields['memo'] = $payload['memo'] ?? null;

        return $fields;
    }

    private function resource(object $agency): array
    {
        return [
            'id' => $agency->public_id,
            'company_name' => $agency->company_name, 'contact_name' => $agency->contact_name,
            'phone' => $agency->phone, 'email' => $agency->email, 'address' => $agency->address,
            'memo' => $agency->memo, 'login_id' => $agency->login_id,
            'advertising_code' => DB::table('agency_advertising_codes')->where('agency_id', $agency->id)->orderBy('id')->value('code'),
            'status' => $agency->status, 'revision' => (int) $agency->revision,
            'created_at' => CarbonImmutable::parse($agency->created_at)->utc()->toIso8601String(),
            'updated_at' => CarbonImmutable::parse($agency->updated_at)->utc()->toIso8601String(),
        ];
    }

    private function row(string $publicId, bool $lock = false): object
    {
        $query = DB::table('agencies')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new V2AgencyException('AGENCY_NOT_FOUND', 404, 'The Agency was not found.');
    }

    private function authorize(V2AdminAuthorizationContext $context, string $operation): void
    {
        $this->authorization->authorizePermission($context, V2Permission::ManageAgency, true, 'agency.'.$operation, true);
    }

    private function invalid(): V2AgencyException
    {
        return new V2AgencyException('AGENCY_INVALID', 422, 'Please check the Agency input.');
    }
}

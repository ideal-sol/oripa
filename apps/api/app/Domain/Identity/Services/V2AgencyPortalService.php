<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Models\V2\Agency;
use App\Models\V2\AgencySession;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SensitiveParameter;

final class V2AgencyPortalService
{
    public function __construct(
        private readonly V2SessionManager $sessions,
        private readonly V2SessionPolicy $policy,
        private readonly V2AgencyPasswordPolicy $passwords,
        private readonly V2AgencyContactFields $contacts,
        private readonly V2EmailNormalizer $emails,
        private readonly V2RateLimiter $limits,
        private readonly V2AuditLogService $audit,
        private readonly V2TemplateMailDeliveryService $mail
    ) {
    }

    public function login(Request $request, #[SensitiveParameter] array $input): array
    {
        $this->validate($input, [
            'login_id' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $ip = (string) $request->ip();
        $this->limits->assertGlobal('agency_login_ip', $ip);
        $this->limits->assertSubject('agency_login_failure', $input['login_id'], false);
        $dummyHash = $this->passwords->hash('Dummy123');
        $result = DB::transaction(function () use ($request, $input, $dummyHash): ?array {
            $agency = Agency::query()->where('login_id', $input['login_id'])->lockForUpdate()->first();
            $valid = password_verify($input['password'], $agency?->password_hash ?? $dummyHash);
            if (! $valid || $agency?->status !== 'active') {
                $this->limits->hitSubject('agency_login_failure', $input['login_id']);
                $this->record($request, 'login', null, 'failure');

                return null;
            }
            $this->sessions->revoke($request, V2Realm::Agency);
            $session = $this->sessions->issue(V2Realm::Agency, $agency->id);
            $this->record($request, 'login', $agency);

            return ['data' => $this->profile($agency), 'session' => $session];
        }, 3);

        return $result ?? throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401, 'Login ID or password is invalid.');
    }

    public function current(Request $request, string $operation = 'read', #[SensitiveParameter] array $input = []): array
    {
        $identity = $request->user('v2_agency');
        if (! $identity instanceof Agency) {
            throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
        }
        if (in_array($operation, ['email', 'password'], true)) {
            $this->limits->assertSubject('agency_credential_change', $identity->public_id);
        }
        try {
            return DB::transaction(function () use ($request, $identity, $operation, $input): array {
                $agency = Agency::query()->whereKey($identity->id)->lockForUpdate()->first();
                $now = $this->policy->currentTime();
                $session = AgencySession::query()
                    ->whereKey($this->sessions->sessionIdHash($request, V2Realm::Agency))
                    ->where('agency_id', $identity->id)->whereNull('revoked_at')
                    ->where('created_at', '<=', $now)->where('idle_expires_at', '>', $now)
                    ->where('absolute_expires_at', '>', $now)->lockForUpdate()->first();
                if ($agency?->status !== 'active' || $session === null) {
                    throw new V2AuthenticationException('AUTHENTICATION_REQUIRED', 401);
                }
                if ($operation === 'read') {
                    return ['data' => $this->profile($agency)];
                }
                if ($operation === 'logout') {
                    $this->validate($input, []);
                    $this->sessions->revoke($request, V2Realm::Agency);
                    $this->record($request, 'logout', $agency);

                    return ['status' => 'logged_out'];
                }
                $rules = match ($operation) {
                    'contact' => $this->contacts->rules(),
                    'email' => [
                        'current_password' => ['required', 'string', 'max:128'],
                        'email' => ['required', 'string', 'email:rfc', 'max:320'],
                    ],
                    'password' => [
                        'current_password' => ['required', 'string', 'max:128'],
                        'password' => ['required', 'string', 'confirmed'],
                        'password_confirmation' => ['required', 'string'],
                    ],
                    default => throw new V2AuthenticationException('AUTHORIZATION_DENIED', 403),
                };
                $this->validate($input, $rules);
                if ($operation !== 'contact' && ! password_verify($input['current_password'], $agency->password_hash)) {
                    throw new V2AuthenticationException('FRESH_AUTHENTICATION_REQUIRED', 403);
                }
                if ($operation === 'password' && ! $this->passwords->isAllowed($input['password'])) {
                    throw new V2AuthenticationException('AGENCY_INVALID', 422);
                }
                $changes = match ($operation) {
                    'contact' => $this->contacts->normalize($input),
                    'email' => ['email' => $this->emails->normalize($input['email']),
                        'normalized_email' => $this->emails->normalize($input['email'])],
                    'password' => ['password_hash' => $this->passwords->hash($input['password'])],
                };
                $agency->forceFill([...$changes, 'revision' => $agency->revision + 1, 'updated_at' => $now])->save();
                $rotated = null;
                if ($operation !== 'contact') {
                    $rotated = $this->sessions->rotateLockedAgencySession($session);
                    if ($operation === 'password') {
                        DB::table('agency_sessions')->where('agency_id', $agency->id)
                            ->where('session_id_hash', '!=', $this->policy->hashSessionId($rotated['token']))
                            ->whereNull('revoked_at')->update(['revoked_at' => $now]);
                    }
                    $this->mail->scheduleAgency('agency_'.$operation.'_changed', $agency->public_id,
                        $agency->revision, $agency->email, [
                            'agency_company_name' => $agency->company_name,
                            'agency_contact_name' => $agency->contact_name,
                            'agency_changed_at' => $now->utc()->toIso8601String(),
                            'agency_login_url' => (string) config('v2_agency.login_url'),
                        ]);
                }
                $this->record($request, $operation.'.update', $agency);

                return ['data' => $this->profile($agency), 'session' => $rotated];
            }, 3);
        } catch (QueryException $exception) {
            throw new V2AuthenticationException(
                $exception->getCode() === '23505' ? 'AGENCY_IDENTITY_CONFLICT' : 'AUTH_SERVICE_UNAVAILABLE',
                $exception->getCode() === '23505' ? 409 : 503
            );
        }
    }

    private function profile(Agency $agency): array
    {
        return [
            'id' => $agency->public_id, 'company_name' => $agency->company_name,
            'contact_name' => $agency->contact_name, 'phone' => $agency->phone,
            'email' => $agency->email, 'address' => $agency->address,
            'login_id' => $agency->login_id, 'status' => $agency->status,
            'advertising_code' => DB::table('agency_advertising_codes')
                ->where('agency_id', $agency->id)->orderBy('id')->value('code'),
        ];
    }

    private function validate(#[SensitiveParameter] array $input, array $rules): void
    {
        if (array_diff(array_keys($input), array_keys($rules)) !== [] || Validator::make($input, $rules)->fails()) {
            throw new V2AuthenticationException('AGENCY_INVALID', 422);
        }
    }

    private function record(Request $request, string $action, ?Agency $agency, string $outcome = 'success'): void
    {
        $this->audit->record('agency.portal.'.$action, [
            'actor_type' => $agency === null ? 'system' : 'agency',
            'actor_public_id' => $agency?->public_id, 'auth_realm' => 'agency',
            'target_type' => 'agency', 'target_public_id' => $agency?->public_id,
            'request_id' => $request->header('X-Request-Id'), 'outcome' => $outcome,
        ]);
    }
}

<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AgencyException;
use App\Domain\Identity\Services\V2AgencyIdentifierGenerator;
use App\Domain\Identity\Services\V2AgencyPasswordPolicy;
use App\Domain\Identity\Services\V2AgencyService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Mail\Services\V2MailTemplateService;
use App\Models\V2\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AgencyManagementTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_agency.mailer' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_agency_password_policy_and_existing_realm_policy_are_independent(): void
    {
        $policy = app(V2AgencyPasswordPolicy::class);
        foreach (['abcdef', '123456', 'abc123', str_repeat('a', 20)] as $candidate) {
            self::assertTrue($policy->isAllowed($candidate));
        }
        foreach (['abc12', str_repeat('a', 21), 'abc_123', '１２３４５６', "abcdef\n", 'abc def'] as $candidate) {
            self::assertFalse($policy->isAllowed($candidate));
        }
        $hash = $policy->hash('abcdef');
        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertTrue(password_verify('abcdef', $hash));
        self::assertFalse(app(V2PasswordPolicy::class)->isAllowed('abcdef'));
        self::assertTrue(app(V2PasswordPolicy::class)->isAllowed(str_repeat('a', 128)));
    }

    public function test_create_update_stop_reactivate_and_credentials_preserve_relations_and_redact_secrets(): void
    {
        $context = $this->context();
        $service = app(V2AgencyService::class);
        $input = $this->input($context);
        $key = (string) Str::uuid7();
        $created = $service->mutate($context, 'create', null, $input, $key);
        $publicId = $created['data']['id'];
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $created['data']['login_id']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $created['data']['advertising_code']);
        self::assertTrue($service->mutate($context, 'create', null, [...$input, 'password' => 'Different234'], $key)['idempotent_replay']);
        $agency = DB::table('agencies')->where('public_id', $publicId)->first();
        self::assertTrue(password_verify($input['password'], $agency->password_hash));
        self::assertSame(1, DB::table('agencies')->count());
        self::assertSame(0, DB::table('user_advertising_attributions')->count());
        $codeId = DB::table('agency_advertising_codes')->where('agency_id', $agency->id)->value('id');
        $userId = $this->user();
        DB::table('user_advertising_attributions')->insert(['user_id' => $userId, 'advertising_code_id' => $codeId, 'attributed_at' => now()]);
        $updated = $service->mutate($context, 'update', $publicId, [
            ...$this->companyInput(), 'login_id' => '000012', 'expected_revision' => 1,
        ], (string) Str::uuid7());
        self::assertSame('000012', $updated['data']['login_id']);
        $context = $this->context(V2AdminRole::Admin);
        foreach (['suspend', 'reactivate', 'password-reset', 'login-information-reissue'] as $offset => $operation) {
            $payload = ['expected_revision' => $offset + 2];
            if ($offset >= 2) $payload['password'] = 'Changed234';
            $operationKey = (string) Str::uuid7();
            $result = $service->mutate($context, $operation, $publicId, $payload, $operationKey);
            self::assertSame($offset + 3, $result['data']['revision']);
            self::assertSame($created['data']['advertising_code'], $result['data']['advertising_code']);
            self::assertTrue($service->mutate($context, $operation, $publicId, $payload, $operationKey)['idempotent_replay']);
        }
        self::assertSame($agency->id, DB::table('agencies')->where('public_id', $publicId)->value('id'));
        self::assertSame($codeId, DB::table('user_advertising_attributions')->where('user_id', $userId)->value('advertising_code_id'));
        self::assertSame(3, DB::table('mail_deliveries')->where('source_type', 'agency')->count());
        self::assertSame(6, DB::table('audit_logs')->where('target_type', 'agency')->count());
        $serialized = json_encode([$created, $updated, $result, DB::table('mail_deliveries')->get(), DB::table('audit_logs')->get(), DB::table('idempotency_records')->get()], JSON_THROW_ON_ERROR);
        foreach ([$input['password'], 'Changed234', $agency->password_hash, hash('sha256', $input['password'])] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_database_identity_uniqueness_and_immutable_advertising_and_attribution_guards(): void
    {
        $context = $this->context();
        $input = $this->input($context);
        $created = app(V2AgencyService::class)->mutate($context, 'create', null, $input, (string) Str::uuid7());
        $agency = DB::table('agencies')->where('public_id', $created['data']['id'])->first();
        $codeId = DB::table('agency_advertising_codes')->where('agency_id', $agency->id)->value('id');
        foreach (['ABC123xy', 'abc123XY'] as $code) {
            DB::table('agency_advertising_codes')->insert(['agency_id' => $agency->id, 'code' => $code]);
        }
        self::assertSame(1, DB::table('agency_advertising_codes')->where('code', 'ABC123xy')->count());
        foreach (['12345', '1234567', '１２３４５６', 'abc123'] as $loginId) {
            $this->rejects(fn () => DB::table('agencies')->where('id', $agency->id)->update(['login_id' => $loginId]));
        }
        foreach (['1234567', '123456789', 'ABC_1234', '１２３４５６７８'] as $code) {
            $this->rejects(fn () => DB::table('agency_advertising_codes')->insert(['agency_id' => $agency->id, 'code' => $code]));
        }
        $this->rejects(fn () => DB::table('agency_advertising_codes')->insert(['agency_id' => $agency->id, 'code' => 'ABC123xy']));
        foreach (['id' => $agency->id + 100, 'public_id' => (string) Str::uuid7()] as $field => $value) {
            $this->rejects(fn () => DB::table('agencies')->where('id', $agency->id)->update([$field => $value]));
        }
        $this->rejects(fn () => DB::table('agencies')->where('id', $agency->id)->delete());
        $this->rejects(fn () => DB::table('agency_advertising_codes')->where('id', $codeId)->update(['code' => 'NEW123xy']));
        $this->rejects(fn () => DB::table('agency_advertising_codes')->where('id', $codeId)->update(['agency_id' => $agency->id]));
        $this->rejects(fn () => DB::table('agency_advertising_codes')->where('id', $codeId)->delete());
        $userId = $this->user();
        $attribution = ['user_id' => $userId, 'advertising_code_id' => $codeId, 'attributed_at' => now()];
        DB::table('user_advertising_attributions')->insert($attribution);
        $this->rejects(fn () => DB::table('user_advertising_attributions')->insert($attribution));
        $this->rejects(fn () => DB::table('user_advertising_attributions')->insert([...$attribution, 'user_id' => $this->user(), 'advertising_code_id' => PHP_INT_MAX]));
        $this->rejects(fn () => DB::table('user_advertising_attributions')->insert([...$attribution, 'user_id' => PHP_INT_MAX]));
        $this->rejects(fn () => DB::table('user_advertising_attributions')->where('user_id', $userId)->update(['attributed_at' => now()]));
        $this->rejects(fn () => DB::table('user_advertising_attributions')->where('user_id', $userId)->delete());
        $this->rejects(fn () => DB::table('users')->where('id', $userId)->delete());
        foreach ([['normalized_email' => strtoupper($agency->email)], ['login_id' => $agency->login_id], ['normalized_email' => $agency->normalized_email]] as $duplicate) {
            $row = (array) $agency;
            unset($row['id']);
            $this->rejects(fn () => DB::table('agencies')->insert([...$row, 'public_id' => (string) Str::uuid7(), ...$duplicate]));
        }
    }

    public function test_generated_conflicts_retry_boundedly_and_suspended_codes_remain_reserved(): void
    {
        $context = $this->context();
        $generator = Mockery::mock(V2AgencyIdentifierGenerator::class);
        $generator->shouldReceive('loginId')->andReturn('000001', '000001', '000002');
        $generator->shouldReceive('advertisingCode')->andReturn('ABC123xy', 'ABC123xy', 'abc123XY');
        app()->instance(V2AgencyIdentifierGenerator::class, $generator);
        $service = app(V2AgencyService::class);
        $first = $service->mutate($context, 'create', null, $this->input($context), (string) Str::uuid7());
        $service->mutate($context, 'suspend', $first['data']['id'], ['expected_revision' => 1], (string) Str::uuid7());
        $secondInput = [...$this->input($context), 'email' => 'other-agency@example.test'];
        $second = $service->mutate($context, 'create', null, $secondInput, (string) Str::uuid7());
        self::assertSame('000002', $second['data']['login_id']);
        self::assertSame('abc123XY', $second['data']['advertising_code']);
        $stuck = Mockery::mock(V2AgencyIdentifierGenerator::class);
        $stuck->shouldReceive('loginId')->times(6)->andReturn('000001');
        $stuck->shouldReceive('advertisingCode')->once()->andReturn('ZBC123xy');
        app()->instance(V2AgencyIdentifierGenerator::class, $stuck);
        $input = [...$this->input($context), 'email' => 'third-agency@example.test'];
        try {
            app(V2AgencyService::class)->mutate($context, 'create', null, $input, (string) Str::uuid7());
            self::fail('Bounded generation must stop.');
        } catch (V2AgencyException $exception) {
            self::assertSame('AGENCY_IDENTIFIER_EXHAUSTED', $exception->errorCode);
        }
        self::assertSame(2, DB::table('agencies')->count());
    }

    public function test_normalized_email_conflicts_revision_tampering_and_idempotency_key_reuse_fail_closed(): void
    {
        $context = $this->context();
        $service = app(V2AgencyService::class);
        $input = $this->input($context);
        $key = (string) Str::uuid7();
        $created = $service->mutate($context, 'create', null, $input, $key);
        foreach ([
            ['create', null, [...$input, 'email' => 'AGENCY@example.test'], (string) Str::uuid7(), 'AGENCY_IDENTITY_CONFLICT'],
            ['create', null, [...$input, 'company_name' => 'Different Company'], $key, 'IDEMPOTENCY_KEY_REUSED'],
            ['create', null, [...$input, 'advertising_code' => 'ABC123xy'], (string) Str::uuid7(), 'AGENCY_INVALID'],
            ['suspend', $created['data']['id'], ['expected_revision' => 2], (string) Str::uuid7(), 'AGENCY_REVISION_CONFLICT'],
            ['update', $created['data']['id'], [...$this->companyInput(), 'login_id' => '000012', 'expected_revision' => 1, 'advertising_code' => 'ABC123xy'], (string) Str::uuid7(), 'AGENCY_INVALID'],
        ] as [$operation, $publicId, $payload, $operationKey, $expected]) {
            try {
                $service->mutate($context, $operation, $publicId, $payload, $operationKey);
                self::fail('Invalid or conflicting mutation must fail.');
            } catch (V2AgencyException $exception) {
                self::assertSame($expected, $exception->errorCode);
            }
        }
        self::assertSame(1, DB::table('agencies')->count());
        self::assertSame(1, DB::table('agency_advertising_codes')->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('source_type', 'agency')->count());
    }

    public function test_owner_admin_http_operations_and_operator_direct_attempts_enforce_fresh_mfa_csrf_and_permissions(): void
    {
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            $context = $this->context($role);
            $this->http()->getJson('/admin/api/v2/agencies')->assertOk();
            $input = [...$this->input($context), 'email' => $role->value.'-agency@example.test'];
            $created = $this->http()->postJson('/admin/api/v2/agencies', $input)->assertCreated();
            $publicId = $created->json('data.id');
            $this->http()->getJson('/admin/api/v2/agencies/'.$publicId)->assertOk();
            $this->http()->putJson('/admin/api/v2/agencies/'.$publicId, [...$this->companyInput(), 'email' => $input['email'], 'login_id' => $role === V2AdminRole::Owner ? '100001' : '100002', 'expected_revision' => 1])->assertOk();
            foreach (['suspend', 'reactivate', 'password-reset', 'login-information-reissue'] as $offset => $operation) {
                $payload = ['expected_revision' => $offset + 2];
                if ($offset >= 2) $payload['password'] = 'Change234';
                $this->http()->postJson('/admin/api/v2/agencies/'.$publicId.'/'.$operation, $payload)->assertOk();
            }
            DB::table('admin_sessions')->where('session_id_hash', $context->sessionIdHash)->update(['mfa_verified_at' => now()->subMinutes(6)]);
            $this->http()->postJson('/admin/api/v2/agencies/'.$publicId.'/suspend', ['expected_revision' => 6])->assertForbidden()->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');
        }
        $this->context(V2AdminRole::Operator);
        $this->http()->getJson('/admin/api/v2/agencies')->assertOk();
        $this->http()->getJson('/admin/api/v2/agencies/'.$publicId)->assertOk();
        $this->http()->postJson('/admin/api/v2/agencies', $input)->assertForbidden();
        $this->http()->postJson('/admin/api/v2/agencies/issuance')->assertForbidden();
        $this->http()->putJson('/admin/api/v2/agencies/'.$publicId, [])->assertForbidden();
        foreach (['suspend', 'reactivate', 'password-reset', 'login-information-reissue'] as $operation) {
            $this->http()->postJson('/admin/api/v2/agencies/'.$publicId.'/'.$operation, [])->assertForbidden();
        }
        $this->context();
        $this->http()->withHeader('X-XSRF-TOKEN', 'invalid')->postJson('/admin/api/v2/agencies/issuance')->assertForbidden()->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_mail_callbacks_are_one_attempt_sample_preview_and_plaintext_free_ledger(): void
    {
        $context = $this->context();
        $service = app(V2AgencyService::class);
        $input = $this->input($context);
        $created = $service->mutate($context, 'create', null, $input, (string) Str::uuid7());
        $publicId = $created['data']['id'];
        $service->mutate($context, 'password-reset', $publicId, ['expected_revision' => 1, 'password' => 'Reset456'], (string) Str::uuid7());
        $service->mutate($context, 'login-information-reissue', $publicId, ['expected_revision' => 2, 'password' => 'Reissue789'], (string) Str::uuid7());
        $bodies = [];
        $mailer = Mockery::mock();
        $mailer->shouldReceive('html')->times(3)->andReturnUsing(function (string $body, callable $callback) use (&$bodies): void {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email());
            $callback($message);
            self::assertSame('agency@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            $bodies[] = $body;
        });
        Mail::shouldReceive('mailer')->with('array')->times(3)->andReturn($mailer);
        Log::spy();
        self::assertSame(3, DB::table('mail_deliveries')->where('status', 'pending')->where('source_type', 'agency')->count());
        $callbacks = app('db.transactions')->getCommittedTransactions()->flatMap(fn ($transaction) => $transaction->getCallbacks());
        foreach ($callbacks as $callback) $callback();
        foreach ($callbacks as $callback) $callback();
        self::assertCount(3, $bodies);
        self::assertStringContainsString($input['password'], $bodies[0]);
        self::assertStringContainsString($created['data']['login_id'], $bodies[0]);
        self::assertStringContainsString($created['data']['advertising_code'], $bodies[0]);
        self::assertStringNotContainsString('Reset456', $bodies[1]);
        self::assertStringContainsString('Reissue789', $bodies[2]);
        self::assertSame(3, DB::table('mail_deliveries')->where('status', 'sent')->where('source_type', 'agency')->count());
        $preview = app(V2MailTemplateService::class)->preview($context, 'agency_account_created', ['body_html' => '<p>{{agency_password}} {{agency_login_id}}</p>']);
        self::assertStringContainsString('Sample123', $preview['body_html']);
        self::assertStringNotContainsString($input['password'], $preview['body_html']);
        $snapshot = json_encode([DB::table('mail_deliveries')->get(), DB::table('audit_logs')->get(), DB::table('idempotency_records')->get()], JSON_THROW_ON_ERROR);
        foreach ([$input['password'], 'Reset456', 'Reissue789'] as $secret) self::assertStringNotContainsString($secret, $snapshot);
        foreach (['debug', 'info', 'warning', 'error', 'critical'] as $level) Log::shouldNotHaveReceived($level);
    }

    private function http(): static
    {
        Auth::forgetGuards();
        $csrf = str_repeat('c', 64);

        return $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])->withUnencryptedCookie('__Host-oripa_admin_session', $this->token)
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)->withHeaders([
                'Origin' => 'https://admin.example.test', 'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => (string) Str::uuid7(),
            ]);
    }

    private function context(V2AdminRole $role = V2AdminRole::Owner): V2AdminAuthorizationContext
    {
        $email = 'agency-admin-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'),
            'role' => $role, 'state' => V2AdminState::Active,
        ]);
        $policy = app(V2SessionPolicy::class);
        $this->token = $policy->issueOpaqueSessionId();
        $sessionHash = $policy->hashSessionId($this->token);
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash, 'admin_id' => $admin->id, 'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false, 'created_at' => now()->subMinute(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6), 'absolute_expires_at' => now()->addHours(11),
        ]);

        return new V2AdminAuthorizationContext($admin->id, $admin->public_id, $role, $sessionHash, hash('sha256', $sessionHash), (string) Str::uuid7());
    }

    private function input(V2AdminAuthorizationContext $context): array
    {
        return [...$this->companyInput(), 'password' => 'Initial123', 'issuance_token' => app(V2AgencyService::class)->issue($context)['issuance_token']];
    }

    private function companyInput(): array
    {
        return ['company_name' => 'QA Agency', 'contact_name' => 'QA Contact', 'phone' => '03-1234-5678', 'email' => 'agency@example.test', 'address' => 'Synthetic address', 'memo' => 'QA only'];
    }

    private function user(): int
    {
        $email = 'agency-user-'.Str::uuid7().'@example.test';

        return DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'email_display' => $email, 'email_normalized' => $email,
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            self::fail('The database guard must reject the mutation.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}

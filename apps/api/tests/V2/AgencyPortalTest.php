<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2AgencyPasswordPolicy;
use App\Domain\Identity\Services\V2AgencyService;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgencyPortalTest extends TestCase
{
    private array $cookies = [];
    private object $agency;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
            'cache.default' => 'array', 'v2_agency.mailer' => 'array',
            'v2_identity.origins.agency' => 'https://agency.example.test',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.origins.user' => 'https://user.example.test',
        ]);
        DB::beginTransaction();
        $this->agency = $this->fixture('000001');
        $this->cookies['__Host-oripa_agency_xsrf'] = str_repeat('c', 64);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    public function test_login_session_hash_cookie_activity_and_logout(): void
    {
        $this->request('GET', '/auth/session')->assertOk()->assertJsonPath('authenticated', false);
        foreach ([['000099', 'Initial123'], ['000001', 'Wrong123']] as [$login, $password]) {
            $this->request('POST', '/auth/login', ['login_id' => $login, 'password' => $password])->assertStatus(401);
        }
        $response = $this->login();
        foreach ($response->headers->getCookies() as $cookie) {
            self::assertTrue($cookie->isSecure());
            self::assertNull($cookie->getDomain());
            self::assertSame('/', $cookie->getPath());
            self::assertSame('strict', $cookie->getSameSite());
            if ($cookie->getName() === '__Host-oripa_agency_session') self::assertTrue($cookie->isHttpOnly());
        }
        $session = DB::table('agency_sessions')->first();
        self::assertSame(hash('sha256', $this->cookies['__Host-oripa_agency_session']), $session->session_id_hash);
        $policy = app(V2SessionPolicy::class);
        self::assertSame(360, (int) $policy->canonicalTime($session->last_activity_at)->diffInMinutes($policy->canonicalTime($session->idle_expires_at)));
        self::assertSame(720, (int) $policy->canonicalTime($session->created_at)->diffInMinutes($policy->canonicalTime($session->absolute_expires_at)));
        $this->travel(1)->hours();
        $this->request('GET', '/auth/session')->assertOk()->assertJsonPath('authenticated', true);
        self::assertGreaterThan($session->last_activity_at, DB::table('agency_sessions')->value('last_activity_at'));
        $old = $this->cookies;
        $this->request('POST', '/auth/logout')->assertOk();
        $this->cookies = $old;
        $this->request('GET', '/me')->assertStatus(401);
        self::assertNotNull(DB::table('agency_sessions')->value('revoked_at'));
        $snapshot = json_encode(DB::table('audit_logs')->get());
        self::assertStringNotContainsString('Initial123', $snapshot);
        self::assertStringNotContainsString($old['__Host-oripa_agency_session'], $snapshot);
        self::assertSame(2, DB::table('audit_logs')->where('action_code', 'agency.portal.login')->where('outcome', 'failure')->count());
    }

    public function test_idle_absolute_expiry_and_canonical_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->login();
        $this->travel(6)->hours();
        $this->request('GET', '/me')->assertStatus(401);
        $this->login();
        $this->travel(5)->hours();
        $this->request('GET', '/me')->assertOk();
        $this->travel(5)->hours();
        $this->request('GET', '/me')->assertOk();
        $this->travel(2)->hours();
        $this->request('GET', '/me')->assertStatus(401);
    }

    public function test_scope_and_every_forbidden_profile_field(): void
    {
        $other = $this->fixture('000002');
        $this->login();
        $this->request('GET', '/me')->assertOk()->assertJsonPath('data.id', $this->agency->public_id)->assertJsonMissingPath('data.memo');
        $this->request('GET', '/me/'.$other->public_id)->assertNotFound();
        foreach (['agency_id', 'agencyPublicId', 'company_name', 'address', 'memo', 'login_id', 'advertising_code', 'status'] as $field) {
            $this->request('PATCH', '/me/contact', ['contact_name' => 'Changed', 'phone' => '03-1111-2222', $field => $other->public_id])->assertStatus(422);
        }
        $this->request('PATCH', '/me/contact', ['contact_name' => ' Changed ', 'phone' => ' 03-1111-2222 '])->assertOk()->assertJsonPath('data.contact_name', 'Changed')->assertJsonPath('data.phone', '03-1111-2222');
        self::assertSame('QA Contact', DB::table('agencies')->where('id', $other->id)->value('contact_name'));
        self::assertSame('QA Company', DB::table('agencies')->where('id', $this->agency->id)->value('company_name'));
    }

    public function test_email_authorization_duplicate_rotation_and_new_email_only_notification(): void
    {
        $other = $this->fixture('000002');
        $otherSession = app(V2SessionManager::class)->issue(V2Realm::Agency, $this->agency->id);
        $this->login();
        $oldToken = $this->cookies['__Host-oripa_agency_session'];
        $this->request('POST', '/me/email', ['email' => 'new@example.test'])->assertStatus(422);
        $this->request('POST', '/me/email', ['current_password' => 'Wrong123', 'email' => 'new@example.test'])->assertStatus(403);
        $this->request('POST', '/me/email', ['current_password' => 'Initial123', 'email' => strtoupper($other->email)])->assertStatus(409);
        $this->request('POST', '/me/email', ['current_password' => 'Initial123', 'email' => ' NEW@EXAMPLE.TEST '])->assertOk()->assertJsonPath('data.email', 'new@example.test');
        self::assertNotSame($oldToken, $this->cookies['__Host-oripa_agency_session']);
        self::assertNull(DB::table('agency_sessions')->where('session_id_hash', hash('sha256', $otherSession['token']))->value('revoked_at'));
        self::assertSame('new@example.test', DB::table('agencies')->where('id', $this->agency->id)->value('email'));
        $this->assertMail('new@example.test', 'agency_email_changed', 'Initial123');
        $this->cookies['__Host-oripa_agency_session'] = $oldToken;
        $this->request('GET', '/me')->assertStatus(401);
    }

    public function test_password_policy_confirmation_rotation_revocation_and_notification(): void
    {
        config(['v2_identity.rate_limits.agency_credential_change' => [100, 900]]);
        $otherSession = app(V2SessionManager::class)->issue(V2Realm::Agency, $this->agency->id);
        $this->login();
        $old = $this->cookies;
        foreach (['abc12', str_repeat('a', 21), 'abc!123', '１２３４５６'] as $password) {
            $this->request('POST', '/me/password', ['current_password' => 'Initial123', 'password' => $password, 'password_confirmation' => $password])->assertStatus(422);
        }
        $this->request('POST', '/me/password', ['current_password' => 'Wrong123', 'password' => 'abcdef', 'password_confirmation' => 'abcdef'])->assertStatus(403);
        $this->request('POST', '/me/password', ['current_password' => 'Initial123', 'password' => 'abcdef', 'password_confirmation' => 'different'])->assertStatus(422);
        $current = 'Initial123';
        foreach (['abcdef', '123456', 'abc123', str_repeat('a', 20)] as $password) {
            $this->request('POST', '/me/password', ['current_password' => $current, 'password' => $password, 'password_confirmation' => $password])->assertOk();
            $current = $password;
        }
        self::assertNotSame($old['__Host-oripa_agency_session'], $this->cookies['__Host-oripa_agency_session']);
        self::assertNotSame($old['__Host-oripa_agency_xsrf'], $this->cookies['__Host-oripa_agency_xsrf']);
        self::assertNotNull(DB::table('agency_sessions')->where('session_id_hash', hash('sha256', $otherSession['token']))->value('revoked_at'));
        $hash = DB::table('agencies')->where('id', $this->agency->id)->value('password_hash');
        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertTrue(password_verify($current, $hash));
        $this->request('GET', '/me')->assertOk();
        $this->assertMail($this->agency->email, 'agency_password_changed', $current, 4);
        $this->cookies = $old;
        $this->request('GET', '/me')->assertStatus(401);
    }

    public function test_admin_stop_reactivate_password_reset_and_reissue_revoke_sessions(): void
    {
        $adminId = DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'email_display' => 'admin@example.test', 'email_normalized' => 'admin@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2AgencyPasswordPolicy::class)->hash('Initial123'),
            'role' => 'owner', 'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = DB::table('admins')->where('id', $adminId)->first();
        $issued = app(V2SessionManager::class)->issue(V2Realm::Admin, $adminId, true);
        $hash = hash('sha256', $issued['token']);
        $context = new V2AdminAuthorizationContext($adminId, $admin->public_id, V2AdminRole::Owner, $hash, hash('sha256', $hash), (string) Str::uuid7());
        $service = app(V2AgencyService::class);
        $this->login();
        $old = $this->cookies;
        foreach (['suspend', 'reactivate', 'password-reset', 'login-information-reissue'] as $offset => $operation) {
            if ($offset > 1) $this->login($offset === 2 ? 'Initial123' : 'Reset123');
            $input = ['expected_revision' => $offset + 1];
            if ($offset > 1) $input['password'] = 'Reset123';
            $service->mutate($context, $operation, $this->agency->public_id, $input, (string) Str::uuid7());
            $this->request('GET', '/me')->assertStatus(401);
            if ($operation === 'suspend') $this->request('POST', '/auth/login', ['login_id' => '000001', 'password' => 'Initial123'])->assertStatus(401);
        }
        $this->cookies = $old;
        $this->request('GET', '/me')->assertStatus(401);
        $this->login('Reset123');
    }

    public function test_realm_and_csrf_separation_and_origin_fetch_metadata(): void
    {
        $this->login();
        $agencyCookies = $this->cookies;
        foreach (['/admin/api/v2/auth/permissions', '/api/v2/me/wallet'] as $path) {
            $response = $this->request('GET', $path);
            self::assertContains($response->status(), [401, 403]);
        }
        foreach (['admin', 'user'] as $realm) {
            $this->cookies = ['__Host-oripa_'.$realm.'_session' => $agencyCookies['__Host-oripa_agency_session'], '__Host-oripa_'.$realm.'_xsrf' => str_repeat('c', 64)];
            $this->request('GET', '/me')->assertStatus(401);
            $this->cookies['__Host-oripa_agency_session'] = $agencyCookies['__Host-oripa_agency_session'];
            $this->request('PATCH', '/me/contact', ['contact_name' => 'Changed', 'phone' => '0311112222'])->assertStatus(403);
        }
        $this->cookies = $agencyCookies;
        foreach ([['HTTP_ORIGIN' => 'https://evil.example.test'], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], ['CONTENT_TYPE' => 'text/plain'], ['HTTP_X_XSRF_TOKEN' => str_repeat('d', 64)]] as $headers) {
            $response = $this->request('PATCH', '/me/contact', ['contact_name' => 'Changed', 'phone' => '0311112222'], $headers);
            self::assertContains($response->status(), [403, 415]);
        }
        $this->request('PATCH', '/me/contact', ['contact_name' => 'Changed', 'phone' => '0311112222'])->assertOk();
        DB::table('agencies')->where('id', $this->agency->id)->update(['status' => 'suspended']);
        $this->request('GET', '/me')->assertStatus(401);
    }

    public function test_real_admin_and_user_sessions_cannot_enter_agency_realm_and_login_rotates(): void
    {
        $this->login();
        $first = $this->cookies;
        $this->login();
        self::assertNotSame($first['__Host-oripa_agency_session'], $this->cookies['__Host-oripa_agency_session']);
        self::assertNotNull(DB::table('agency_sessions')->where('session_id_hash', hash('sha256', $first['__Host-oripa_agency_session']))->value('revoked_at'));
        foreach ([V2Realm::Admin, V2Realm::User] as $realm) {
            $values = [
                'public_id' => (string) Str::uuid7(), 'email_display' => $realm->value.'@example.test',
                'email_normalized' => $realm->value.'@example.test', 'email_verified_at' => now(),
                'password_hash' => app(V2AgencyPasswordPolicy::class)->hash('Initial123'),
                'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ];
            if ($realm === V2Realm::Admin) $values['role'] = 'owner';
            $internalId = DB::table($realm === V2Realm::Admin ? 'admins' : 'users')->insertGetId($values);
            $session = app(V2SessionManager::class)->issue($realm, $internalId, true);
            $this->cookies = ['__Host-oripa_'.$realm->value.'_session' => $session['token']];
            $this->request('GET', '/me')->assertStatus(403)->assertJsonPath('code', 'AUTHORIZATION_DENIED');
            $this->cookies['__Host-oripa_agency_session'] = $session['token'];
            unset($this->cookies['__Host-oripa_'.$realm->value.'_session']);
            $this->request('GET', '/me')->assertStatus(401);
        }
    }

    public function test_failed_login_is_rate_limited_and_no_session_is_issued(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->request('POST', '/auth/login', ['login_id' => '000001', 'password' => 'Wrong123'])->assertStatus(401);
        }
        $this->request('POST', '/auth/login', ['login_id' => '000001', 'password' => 'Initial123'])->assertStatus(429);
        self::assertSame(0, DB::table('agency_sessions')->count());
    }

    private function assertMail(string $recipient, string $key, string $password, int $count = 1): void
    {
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('html')->times($count)->andReturnUsing(function (string $body, callable $callback) use ($recipient, $password): void {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email());
            $callback($message);
            self::assertCount(1, $message->getSymfonyMessage()->getTo());
            self::assertSame($recipient, $message->getSymfonyMessage()->getTo()[0]->getAddress());
            self::assertStringNotContainsString($password, $body);
            self::assertSame([], $message->getSymfonyMessage()->getCc());
            self::assertSame([], $message->getSymfonyMessage()->getBcc());
        });
        Mail::shouldReceive('mailer')->with('array')->times($count)->andReturn($mailer);
        $callbacks = app('db.transactions')->getCommittedTransactions()->flatMap(fn ($transaction) => $transaction->getCallbacks());
        foreach ($callbacks as $callback) $callback();
        self::assertSame($count, DB::table('mail_deliveries')->where('status', 'sent')->count());
        self::assertSame($key, DB::table('mail_templates')->where('id', DB::table('mail_deliveries')->value('mail_template_id'))->value('template_key'));
        self::assertStringNotContainsString($password, json_encode([DB::table('mail_deliveries')->get(), DB::table('audit_logs')->get()]));
    }

    private function login(string $password = 'Initial123')
    {
        return $this->request('POST', '/auth/login', ['login_id' => '000001', 'password' => $password])->assertOk();
    }

    private function request(string $method, string $path, array $body = [], array $headers = [])
    {
        Auth::forgetGuards();
        $response = $this->call($method, str_starts_with($path, '/api/') || str_starts_with($path, '/admin/') ? $path : '/agency/api/v2'.$path, [], $this->cookies, [], [
            'HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'https://agency.example.test', 'HTTP_X_XSRF_TOKEN' => $this->cookies['__Host-oripa_agency_xsrf'] ?? str_repeat('c', 64),
            ...$headers,
        ], $method === 'GET' ? null : json_encode($body));
        foreach ($response->headers->getCookies() as $cookie) $this->cookies[$cookie->getName()] = $cookie->getValue();

        return $response;
    }

    private function fixture(string $login): object
    {
        $internalId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_name' => 'QA Company', 'contact_name' => 'QA Contact',
            'phone' => '03-1234-5678', 'email' => $login.'@example.test', 'normalized_email' => $login.'@example.test',
            'address' => 'Synthetic address', 'memo' => 'ADMIN PRIVATE MEMO', 'login_id' => $login,
            'password_hash' => app(V2AgencyPasswordPolicy::class)->hash('Initial123'),
            'status' => 'active', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agency_advertising_codes')->insert(['agency_id' => $internalId, 'code' => 'AD'.$login]);

        return DB::table('agencies')->where('id', $internalId)->first();
    }
}

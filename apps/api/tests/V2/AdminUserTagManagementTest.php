<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Exceptions\V2UserTagException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Identity\Services\V2UserTagService;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUserTagManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_owner_and_admin_manage_tags_while_operator_is_read_only(): void
    {
        $service = app(V2UserTagService::class);
        $owner = $this->context(V2AdminRole::Owner);
        $admin = $this->context(V2AdminRole::Admin);
        $operator = $this->context(V2AdminRole::Operator);

        $created = $service->create($owner, [
            'name' => '  VIP   Member  ',
            'is_active' => true,
        ], (string) Str::uuid7());
        self::assertSame('VIP Member', $created['data']['name']);
        self::assertTrue(Str::isUuid($created['data']['id']));
        self::assertSame(1, $created['data']['revision']);
        self::assertSame($created['data']['id'], $service->listing($operator, null)['items'][0]['id']);

        $updated = $service->update($admin, $created['data']['id'], [
            'name' => 'VIP Member',
            'is_active' => false,
            'expected_revision' => 1,
        ], (string) Str::uuid7());
        self::assertFalse($updated['data']['is_active']);
        self::assertSame(2, $updated['data']['revision']);

        try {
            $service->create($operator, ['name' => 'Operator Tag', 'is_active' => true], (string) Str::uuid7());
            self::fail('Operator mutations must be denied.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }
    }

    public function test_names_revision_and_idempotency_fail_closed(): void
    {
        $service = app(V2UserTagService::class);
        $owner = $this->context(V2AdminRole::Owner);
        $key = (string) Str::uuid7();
        $created = $service->create($owner, [
            'name' => 'Premium',
            'is_active' => true,
        ], $key);
        self::assertFalse($created['idempotent_replay']);
        self::assertTrue($service->create($owner, [
            'name' => 'Premium',
            'is_active' => true,
        ], $key)['idempotent_replay']);

        foreach ([
            ['name' => 'premium', 'is_active' => true],
            ['name' => "\t", 'is_active' => true],
        ] as $input) {
            try {
                $service->create($owner, $input, (string) Str::uuid7());
                self::fail('Duplicate or invalid names must be rejected.');
            } catch (V2UserTagException $exception) {
                self::assertContains($exception->errorCode, [
                    'USER_TAG_NAME_CONFLICT',
                    'USER_TAG_INVALID',
                ]);
            }
        }

        try {
            $service->update($owner, $created['data']['id'], [
                'name' => 'Premium',
                'is_active' => false,
                'expected_revision' => 2,
            ], (string) Str::uuid7());
            self::fail('A stale tag revision must be rejected.');
        } catch (V2UserTagException $exception) {
            self::assertSame('USER_TAG_REVISION_CONFLICT', $exception->errorCode);
        }

        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'user.tag.created')->count());
    }

    public function test_inactive_existing_assignment_is_retained_but_cannot_be_newly_assigned(): void
    {
        $service = app(V2UserTagService::class);
        $owner = $this->context(V2AdminRole::Owner);
        $firstUser = $this->user('first');
        $secondUser = $this->user('second');
        $tag = $service->create($owner, [
            'name' => 'Campaign target',
            'is_active' => true,
        ], (string) Str::uuid7())['data'];

        $assigned = $service->assign(
            $owner,
            $firstUser,
            $tag['id'],
            ['expected_revision' => 1],
            (string) Str::uuid7()
        );
        self::assertCount(1, $assigned['data']['tags']);
        self::assertSame(2, $assigned['data']['revision']);

        $service->update($owner, $tag['id'], [
            'name' => $tag['name'],
            'is_active' => false,
            'expected_revision' => 1,
        ], (string) Str::uuid7());
        $retained = $service->userTags($this->context(V2AdminRole::Operator), $firstUser);
        self::assertFalse($retained['tags'][0]['is_active']);

        try {
            $service->assign(
                $owner,
                $secondUser,
                $tag['id'],
                ['expected_revision' => 1],
                (string) Str::uuid7()
            );
            self::fail('Inactive tags must not be newly assigned.');
        } catch (V2UserTagException $exception) {
            self::assertSame('USER_TAG_INACTIVE', $exception->errorCode);
        }

        $detached = $service->detach(
            $owner,
            $firstUser,
            $tag['id'],
            ['expected_revision' => 2],
            (string) Str::uuid7()
        );
        self::assertSame([], $detached['data']['tags']);
        self::assertSame(3, $detached['data']['revision']);
        self::assertDatabaseMissing('user_tag_assignments', [
            'user_id' => DB::table('users')->where('public_id', $firstUser)->value('id'),
        ]);
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'user.tag.assigned')->count());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'user.tag.detached')->count());
    }

    public function test_assignment_duplicate_occ_and_admin_api_contract_are_safe(): void
    {
        $service = app(V2UserTagService::class);
        $admin = $this->context(V2AdminRole::Admin);
        $user = $this->user('contract');
        $tag = $service->create($admin, [
            'name' => 'Read model',
            'is_active' => true,
        ], (string) Str::uuid7())['data'];
        $service->assign($admin, $user, $tag['id'], ['expected_revision' => 1], (string) Str::uuid7());

        try {
            $service->assign($admin, $user, $tag['id'], ['expected_revision' => 2], (string) Str::uuid7());
            self::fail('Duplicate assignment must be rejected.');
        } catch (V2UserTagException $exception) {
            self::assertSame('USER_TAG_ALREADY_ASSIGNED', $exception->errorCode);
        }
        try {
            $service->detach($admin, $user, $tag['id'], ['expected_revision' => 1], (string) Str::uuid7());
            self::fail('A stale assignment revision must be rejected.');
        } catch (V2UserTagException $exception) {
            self::assertSame('USER_TAG_ASSIGNMENT_REVISION_CONFLICT', $exception->errorCode);
        }

        $this->getJson('/admin/api/v2/user-tags')->assertUnauthorized();
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/api/v2/user-tags')
                || str_contains($route->uri(), '/tags'))
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => $method.' '.$route->uri()));
        self::assertContains('GET admin/api/v2/user-tags', $routes);
        self::assertContains('POST admin/api/v2/user-tags', $routes);
        self::assertContains('PUT admin/api/v2/user-tags/{tagId}', $routes);
        self::assertContains('GET admin/api/v2/users/{userId}/tags', $routes);
        self::assertContains('POST admin/api/v2/users/{userId}/tags/{tagId}', $routes);
        self::assertContains('DELETE admin/api/v2/users/{userId}/tags/{tagId}', $routes);

        $encoded = json_encode($service->userTags($admin, $user), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('user_tag_id', $encoded);
        self::assertStringNotContainsString('assigned_by_admin_public_id', $encoded);
    }

    public function test_invalid_cursor_uses_problem_details_contract(): void
    {
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $email = 'user-tag-api-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now()->subMinute(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(11),
        ]);

        $response = $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token)
            ->getJson('/admin/api/v2/user-tags?cursor=internal-id')
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORTING_CURSOR_INVALID');
        self::assertStringContainsString(
            'application/problem+json',
            (string) $response->headers->get('Content-Type')
        );
        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'user-tags-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $sessionHash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now()->subMinute(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(11),
        ]);

        return new V2AdminAuthorizationContext(
            $admin->id,
            $admin->public_id,
            $role,
            $sessionHash,
            hash('sha256', $sessionHash),
            (string) Str::uuid7()
        );
    }

    private function user(string $suffix): string
    {
        $email = 'tag-target-'.$suffix.'-'.Str::uuid7().'@example.test';
        $publicId = (string) Str::uuid7();
        DB::table('users')->insert([
            'public_id' => $publicId,
            'display_name' => 'Synthetic '.$suffix,
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }
}

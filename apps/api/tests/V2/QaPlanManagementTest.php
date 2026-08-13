<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Domain\QaDraw\Services\V2QaPlanManagementService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QaPlanManagementTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PRIZE_S_ID = '0198a001-0000-7000-8000-000000000009';
    private const PRIZE_ID = '0198a001-0000-7000-8000-000000000010';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-30T00:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('q', 32)),
        ]);
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($fixture);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_schema_adds_history_preserving_assignment_and_revision_guards(): void
    {
        self::assertTrue(Schema::hasTable('qa_draw_plan_assignments'));
        self::assertTrue(Schema::hasColumn('qa_draw_plans', 'code'));
        self::assertTrue(Schema::hasColumn('qa_draw_plans', 'revision'));
        self::assertTrue(Schema::hasColumn('qa_draw_plans', 'archived_at'));
        self::assertTrue(Schema::hasColumn('qa_test_user_modes', 'revision'));
        self::assertTrue(Schema::hasTable('qa_gacha_guarantee_assignments'));
        self::assertTrue(Schema::hasColumn('draw_requests', 'qa_gacha_guarantee_assignment_id'));
        self::assertTrue(Schema::hasColumn('draw_results', 'qa_gacha_guarantee_assignment_id'));
        self::assertTrue(Schema::hasColumn('qa_draw_executions', 'qa_gacha_guarantee_assignment_id'));
        self::assertFalse(Schema::hasColumn('qa_draw_plan_assignments', 'tenant_id'));

        [$owner, $user] = [$this->admin(V2AdminRole::Owner), $this->user()];
        $plan = $this->legacyPlan($owner, $user);
        self::assertDatabaseHas('qa_draw_plan_assignments', [
            'qa_draw_plan_id' => DB::table('qa_draw_plans')
                ->where('public_id', $plan['id'])->value('id'),
            'user_id' => $user->id,
            'status' => 'assigned',
        ]);
        $assignmentPublicId = (string) DB::table('qa_draw_plan_assignments')
            ->where('qa_draw_plan_id', DB::table('qa_draw_plans')
                ->where('public_id', $plan['id'])->value('id'))
            ->value('public_id');
        self::assertSame('7', $assignmentPublicId[14]);
        try {
            DB::table('qa_draw_plan_assignments')->delete();
            self::fail('Assignment physical delete must fail.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::table('qa_draw_plans')
                ->where('public_id', $plan['id'])
                ->update(['title' => 'Revision bypass']);
            self::fail('Plan updates must advance the revision explicitly.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::table('qa_draw_plans')
                ->where('public_id', $plan['id'])
                ->update([
                    'user_id' => $this->user()->id,
                    'revision' => DB::raw('revision + 1'),
                ]);
            self::fail('Plan identity foreign keys must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::table('qa_draw_plans')
                ->where('public_id', $plan['id'])
                ->update(['code' => 'QA-CHANGED']);
            self::fail('Plan code must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_owner_manages_mode_plan_assignments_preflight_and_canonical_replay(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        $context = $this->context($owner);
        $service = app(V2QaPlanManagementService::class);
        $primary = $this->user();
        $secondary = $this->user();

        $mode = $service->saveTestUser(
            $context,
            $primary->public_id,
            'qa-mode-primary-key',
            [
                'reason' => 'Release verification',
                'starts_at' => '2026-08-13T00:00:00Z',
                'ends_at' => '2026-08-14T00:00:00Z',
            ]
        );
        self::assertFalse($mode['idempotent_replay']);
        self::assertNull(DB::table('qa_test_user_modes')
            ->where('user_id', $primary->id)->value('ends_at'));
        $service->saveTestUser(
            $context,
            $secondary->public_id,
            'qa-mode-secondary-key',
            ['reason' => 'Concurrent verification']
        );
        $request = [
            'user_id' => $primary->public_id,
            'gacha_id' => self::GACHA_ID,
            'title' => 'QA managed plan',
            'reason' => 'Release verification',
            'starts_at' => null,
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'items' => [[
                'prize_id' => self::PRIZE_ID,
                'quantity' => 10,
                'sort_order' => 1,
                'fixed_image_asset_id' => null,
                'fixed_video_asset_id' => null,
            ]],
        ];
        $created = $service->createPlan($context, 'qa-plan-create-key', $request);
        $replay = $service->createPlan($context, 'qa-plan-create-key', $request);
        self::assertFalse($created['idempotent_replay']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($created['data']['id'], $replay['data']['id']);
        self::assertDatabaseCount('qa_draw_plans', 1);
        self::assertCount(1, $created['data']['assignments']);
        try {
            $service->createPlan(
                $context,
                'qa-plan-create-key',
                [...$request, 'title' => 'Different request']
            );
            self::fail('An Idempotency-Key cannot be reused for another request.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
        try {
            $service->updatePlan(
                $context,
                $created['data']['id'],
                'qa-plan-unknown-field-key',
                [
                    'revision' => 1,
                    'title' => 'Unknown input',
                    'reason' => 'Unknown input',
                    'starts_at' => null,
                    'ends_at' => now()->addHour()->toIso8601String(),
                    'internal_id' => 1,
                ]
            );
            self::fail('Unknown fields must be rejected.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_CONFIGURATION_INVALID', $exception->errorCode);
            self::assertSame(422, $exception->status);
        }

        $assigned = $service->assign(
            $context,
            $created['data']['id'],
            'qa-plan-assign-key',
            ['revision' => 1, 'user_id' => $secondary->public_id]
        );
        self::assertSame(2, $assigned['data']['revision']);
        $detail = $service->plan($context, $created['data']['id']);
        self::assertCount(2, $detail['assignments']);
        $preflight = $service->preflight($context, $created['data']['id']);
        self::assertTrue($preflight['valid']);
        self::assertSame(2, $preflight['assigned_test_user_count']);
        self::assertSame(10, $preflight['remaining_draw_count']);
        self::assertDatabaseHas('outbox_messages', ['topic' => 'qa.plan.change']);
    }

    public function test_stale_revision_duplicate_assignment_and_invalid_users_fail_closed(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        $context = $this->context($owner);
        $service = app(V2QaPlanManagementService::class);
        $user = $this->user();
        app(V2QaDrawAdminService::class)->saveMode(
            $context,
            $user->public_id,
            'QA assignment'
        );
        $plan = $this->legacyPlan($owner, $user);

        try {
            $service->updatePlan(
                $context,
                $plan['id'],
                'qa-stale-update-key',
                [
                    'revision' => 99,
                    'title' => 'Stale',
                    'reason' => 'Stale',
                    'starts_at' => null,
                    'ends_at' => now()->addHour()->toIso8601String(),
                ]
            );
            self::fail('Stale revision must fail.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_REVISION_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
        try {
            $service->assign(
                $context,
                $plan['id'],
                'qa-duplicate-assignment-key',
                ['revision' => 1, 'user_id' => $user->public_id]
            );
            self::fail('Duplicate assignment must fail.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_ASSIGNMENT_CONFLICT', $exception->errorCode);
        }

        $closed = $this->user(V2UserState::Closed);
        try {
            $service->saveTestUser(
                $context,
                $closed->public_id,
                'qa-closed-user-key',
                [
                    'reason' => 'Invalid',
                ]
            );
            self::fail('Closed User cannot enter QA mode.');
        } catch (V2QaDrawException $exception) {
            self::assertSame(422, $exception->status);
        }
    }

    public function test_owner_only_boundary_candidate_search_and_no_draw_mutation_route(): void
    {
        $service = app(V2QaPlanManagementService::class);
        $user = $this->user();
        foreach ([V2AdminRole::Admin, V2AdminRole::Operator] as $role) {
            try {
                $service->candidates($this->context($this->admin($role)), ['q' => $user->public_id]);
                self::fail('Non-Owner must not read QA management.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame(403, $exception->status);
            }
        }
        $candidates = $service->candidates(
            $this->context($this->admin(V2AdminRole::Owner)),
            ['q' => $user->public_id]
        );
        self::assertCount(1, $candidates['items']);
        self::assertSame($user->public_id, $candidates['items'][0]['user_id']);
        self::assertNull($candidates['items'][0]['mode_id']);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): array => [$route->uri(), $route->methods()])
            ->all();
        self::assertFalse(collect($routes)->contains(
            fn (array $route): bool => str_starts_with($route[0], 'admin/api/v2/qa/')
                && str_contains($route[0], 'draw')
                && in_array('POST', $route[1], true)
        ));
    }

    public function test_owner_manages_multiple_persistent_gacha_guarantees_with_occ_and_replay(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        $context = $this->context($owner);
        $service = app(V2QaPlanManagementService::class);
        $first = $this->user();
        $second = $this->user();
        foreach ([$first, $second] as $index => $user) {
            $service->saveTestUser(
                $context,
                $user->public_id,
                'qa-persistent-mode-'.$index,
                ['reason' => 'Persistent Gacha guarantee']
            );
        }

        $firstSaved = $service->saveGachaGuarantee(
            $context,
            self::GACHA_ID,
            'qa-persistent-first',
            ['user_id' => $first->public_id, 'prize_id' => self::PRIZE_ID]
        );
        $firstReplay = $service->saveGachaGuarantee(
            $context,
            self::GACHA_ID,
            'qa-persistent-first',
            ['user_id' => $first->public_id, 'prize_id' => self::PRIZE_ID]
        );
        $service->saveGachaGuarantee(
            $context,
            self::GACHA_ID,
            'qa-persistent-second',
            ['user_id' => $second->public_id, 'prize_id' => self::PRIZE_S_ID]
        );

        self::assertFalse($firstSaved['idempotent_replay']);
        self::assertTrue($firstReplay['idempotent_replay']);
        self::assertSame($firstSaved['data']['id'], $firstReplay['data']['id']);
        $collection = $service->gachaGuarantees($context, self::GACHA_ID);
        self::assertCount(2, $collection['items']);
        self::assertCount(2, $collection['test_users']);
        self::assertCount(2, $collection['prizes']);

        $updated = $service->saveGachaGuarantee(
            $context,
            self::GACHA_ID,
            'qa-persistent-first-update',
            [
                'revision' => $firstSaved['data']['revision'],
                'user_id' => $first->public_id,
                'prize_id' => self::PRIZE_S_ID,
            ]
        );
        self::assertSame(2, $updated['data']['revision']);
        self::assertSame(self::PRIZE_S_ID, $updated['data']['prize']['id']);
        self::assertDatabaseCount('qa_gacha_guarantee_assignments', 2);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.guarantee.saved']);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_type' => 'qa_gacha_guarantee_assignment',
        ]);

        try {
            $service->saveGachaGuarantee(
                $context,
                self::GACHA_ID,
                'qa-persistent-first-stale',
                [
                    'revision' => 1,
                    'user_id' => $first->public_id,
                    'prize_id' => self::PRIZE_ID,
                ]
            );
            self::fail('A stale QA Gacha guarantee revision must conflict.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_REVISION_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }

        $legacyUser = $this->user();
        $service->saveTestUser(
            $context,
            $legacyUser->public_id,
            'qa-legacy-mode',
            ['reason' => 'Legacy plan conflict']
        );
        $this->legacyPlan($owner, $legacyUser);
        try {
            $service->saveGachaGuarantee(
                $context,
                self::GACHA_ID,
                'qa-persistent-legacy-conflict',
                ['user_id' => $legacyUser->public_id, 'prize_id' => self::PRIZE_ID]
            );
            self::fail('A legacy active Plan and persistent guarantee must not coexist.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_ACTIVE_PLAN_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }

        $planAdmin = $this->admin(V2AdminRole::Owner);
        $planContext = $this->context($planAdmin);
        $planOwner = $this->user();
        $service->saveTestUser(
            $planContext,
            $planOwner->public_id,
            'qa-plan-owner-mode',
            ['reason' => 'Plan assignment target']
        );
        $plan = $this->legacyPlan($planAdmin, $planOwner);
        try {
            $service->assign(
                $planContext,
                $plan['id'],
                'qa-plan-persistent-assignment-conflict',
                [
                    'revision' => $plan['revision'],
                    'user_id' => $first->public_id,
                ]
            );
            self::fail('A User with a persistent guarantee cannot join an active legacy Plan.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_ACTIVE_PLAN_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
    }

    public function test_http_routes_require_admin_realm_and_owner_permission(): void
    {
        $user = $this->user();
        $owner = $this->admin(V2AdminRole::Owner);
        $ownerToken = $this->sessionToken($owner);
        app(V2QaPlanManagementService::class)->saveTestUser(
            $this->context($owner),
            $user->public_id,
            'qa-http-mode-compatibility',
            ['reason' => 'HTTP compatibility verification']
        );
        $response = $this->asAdmin($ownerToken)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
            ])
            ->getJson('/admin/api/v2/qa/plans')
            ->assertOk()
            ->assertJsonStructure(['items', 'next_cursor']);
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $modeResponse = $this->asAdmin($ownerToken)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
            ])
            ->getJson('/admin/api/v2/users/'.$user->public_id.'/qa-mode')
            ->assertOk()
            ->assertJsonPath('user_id', $user->public_id)
            ->assertJsonPath('mode.ends_at', '9999-12-31T23:59:59Z');
        self::assertStringContainsString('private', $modeResponse->headers->get('Cache-Control'));
        $guarantees = $this->asAdmin($ownerToken)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
            ])
            ->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/qa-guarantees')
            ->assertOk()
            ->assertJsonStructure(['gacha_id', 'items', 'test_users', 'prizes']);
        self::assertStringContainsString('private', $guarantees->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $guarantees->headers->get('Cache-Control'));

        $adminToken = $this->sessionToken($this->admin(V2AdminRole::Admin));
        $this->asAdmin($adminToken)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
            ])
            ->getJson('/admin/api/v2/qa/plans')
            ->assertForbidden()
            ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
    }

    public function test_http_route_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/admin/api/v2/qa/plans')->assertUnauthorized();
        $this->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/qa-guarantees')
            ->assertUnauthorized();
    }

    private function legacyPlan(Admin $owner, User $user): array
    {
        return app(V2QaDrawAdminService::class)->createPlan(
            $this->context($owner),
            $user->public_id,
            self::GACHA_ID,
            'QA plan',
            'Release verification',
            null,
            now()->addHours(2)->toIso8601String(),
            [[
                'prize_id' => self::PRIZE_ID,
                'quantity' => 10,
                'sort_order' => 1,
                'fixed_image_asset_id' => null,
                'fixed_video_asset_id' => null,
            ]]
        );
    }

    private function user(V2UserState $state = V2UserState::Active): User
    {
        $id = Str::uuid();

        return User::query()->create([
            'email_display' => "qa-{$id}@example.test",
            'email_normalized' => "qa-{$id}@example.test",
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => $state,
        ]);
    }

    private function admin(V2AdminRole $role): Admin
    {
        $id = Str::uuid();

        return Admin::query()->create([
            'email_display' => "qa-admin-{$id}@example.test",
            'email_normalized' => "qa-admin-{$id}@example.test",
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
    }

    private function context(Admin $admin): V2AdminAuthorizationContext
    {
        $hash = hash('sha256', random_bytes(32));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(12),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }

    private function sessionToken(Admin $admin): string
    {
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => $created->copy()->addHours(12),
        ]);

        return $token;
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }
}

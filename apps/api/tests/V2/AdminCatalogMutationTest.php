<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Catalog\Services\V2CatalogMutationRateLimiter;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AdminCatalogMutationTest extends TestCase
{
    private const PUBLISHED_CATEGORY_ID = '0198a001-0000-7000-8000-000000000001';

    private const PUBLISHED_TAG_ID = '0198a001-0000-7000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($fixture);
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_owner_and_admin_create_all_master_types_but_operator_is_read_only(): void
    {
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            $token = $this->createAdminSession($role);
            foreach ([
                ['categories', $this->categoryInput($role->value.'-category')],
                ['tags', $this->tagInput($role->value.'-tag')],
            ] as [$resource, $input]) {
                Auth::forgetGuards();
                $this->mutatingRequest($token, 'POST', "/admin/api/v2/catalog/{$resource}", $input)
                    ->assertCreated()
                    ->assertJsonPath('data.code', $input['code'])
                    ->assertJsonPath('data.revision', 1)
                    ->assertJsonPath('data.is_archived', false)
                    ->assertJsonPath('idempotent_replay', false)
                    ->assertHeader('Cache-Control', 'no-store, private');
            }

            Auth::forgetGuards();
            $this->mutatingRequest(
                $token,
                'POST',
                '/admin/api/v2/catalog/ranks',
                $this->rankMasterInput($role->value.' rank')
            )->assertCreated()
                ->assertJsonPath('data.rank_name', $role->value.' rank')
                ->assertJsonPath('data.show_total_stock', false)
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.revision', 1)
                ->assertJsonPath('idempotent_replay', false)
                ->assertHeader('Cache-Control', 'no-store, private');

            Auth::forgetGuards();
            $asset = $this->mutatingRequest(
                $token,
                'POST',
                '/admin/api/v2/catalog/presentation-assets',
                $this->assetInput($role->value.'-asset')
            )->assertCreated()->json('data');
            Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/prizes',
            $this->prizeInput($role->value.'-prize', $asset['id'])
            )->assertStatus(405);
        }

        $operator = $this->createAdminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $operator,
            'POST',
            '/admin/api/v2/catalog/categories',
            $this->categoryInput('operator-category')
        )->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');
        foreach ([
            [
                '/admin/api/v2/catalog/presentation-assets/0198a001-0000-7000-8000-000000000005',
                ['public_path' => '/must-not-leak-immutability.png'],
            ],
        ] as [$uri, $payload]) {
            Auth::forgetGuards();
            $this->mutatingRequest($operator, 'PUT', $uri, $payload)
                ->assertForbidden()
                ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
        }
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.permission_denied',
            'outcome' => 'failure',
        ]);
    }

    public function test_create_update_archive_and_replay_are_canonical(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $input = $this->categoryInput('mutation-category');
        $key = 'catalog-create-canonical-key';
        $created = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/categories',
            $input,
            $key
        )->assertCreated();
        $publicId = $created->json('data.id');
        self::assertTrue(Str::isUuid($publicId));

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/categories',
            $input,
            $key
        )->assertCreated()
            ->assertJsonPath('data.id', $publicId)
            ->assertJsonPath('idempotent_replay', true)
            ->assertHeader('Idempotency-Replayed', 'true');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/categories',
            [...$input, 'name' => 'Different'],
            $key
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            "/admin/api/v2/catalog/categories/{$publicId}/archive",
            ['expected_revision' => 1],
            $key
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $updatedInput = [
            'expected_revision' => 1,
            'slug' => 'mutation-category-updated',
            'name' => "Cafe\u{0301} Category",
            'description' => 'Updated plain text',
            'sort_order' => 5,
            'is_visible' => false,
        ];
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            "/admin/api/v2/catalog/categories/{$publicId}",
            $updatedInput
        )->assertOk()
            ->assertJsonPath('data.name', 'Café Category')
            ->assertJsonPath('data.revision', 2);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            "/admin/api/v2/catalog/categories/{$publicId}",
            $updatedInput
        )->assertConflict()->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            "/admin/api/v2/catalog/categories/{$publicId}/archive",
            ['expected_revision' => 2]
        )->assertOk()
            ->assertJsonPath('data.is_visible', false)
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.revision', 3);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            "/admin/api/v2/catalog/categories/{$publicId}",
            [...$updatedInput, 'expected_revision' => 3]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_RESOURCE_ARCHIVED');

        self::assertDatabaseHas('audit_logs', ['action_code' => 'catalog.master.created']);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'catalog.master.updated']);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'catalog.master.archived']);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.idempotent_replay',
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => $publicId,
            'event_type' => 'catalog.master.archived',
        ]);
    }

    public function test_validation_code_immutability_and_unique_constraints_fail_closed(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $input = $this->tagInput('validation-tag');
        $created = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/tags',
            $input
        )->assertCreated();
        $id = $created->json('data.id');

        foreach ([
            [...$this->tagInput('unknown-field'), 'unknown' => true],
            [...$this->tagInput('html-field'), 'name' => '<script>alert(1)</script>'],
            [...$this->tagInput('negative-sort'), 'sort_order' => -1],
        ] as $invalid) {
            Auth::forgetGuards();
            $this->mutatingRequest(
                $token,
                'POST',
                '/admin/api/v2/catalog/tags',
                $invalid
            )->assertUnprocessable()->assertJsonPath('code', 'CATALOG_MUTATION_INVALID');
        }

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            "/admin/api/v2/catalog/tags/{$id}",
            [
                'code' => 'changed-code',
                'expected_revision' => 1,
                'slug' => $input['slug'],
                'name' => $input['name'],
                'sort_order' => 1,
                'is_visible' => true,
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_CODE_IMMUTABLE');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/tags',
            [...$this->tagInput('duplicate-tag'), 'slug' => $input['slug']]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_MASTER_CONFLICT');
    }

    public function test_published_category_allows_presentation_changes_but_protects_slug_and_archive(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $current = $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/categories/'.self::PUBLISHED_CATEGORY_ID)
            ->assertOk()
            ->json('data');

        foreach ([
            ['name' => 'Published Category Changed'],
            ['description' => 'Published description changed'],
            ['sort_order' => 25],
            ['is_visible' => false],
            ['is_visible' => true],
        ] as $change) {
            Auth::forgetGuards();
            $current = $this->mutatingRequest(
                $token,
                'PUT',
                '/admin/api/v2/catalog/categories/'.self::PUBLISHED_CATEGORY_ID,
                [
                    'expected_revision' => $current['revision'],
                    'slug' => $current['slug'],
                    'name' => $current['name'],
                    'description' => $current['description'],
                    'sort_order' => $current['sort_order'],
                    'is_visible' => $current['is_visible'],
                    ...$change,
                ]
            )->assertOk()->json('data');
        }

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/categories/'.self::PUBLISHED_CATEGORY_ID,
            [
                'expected_revision' => $current['revision'] - 1,
                'slug' => $current['slug'],
                'name' => $current['name'],
                'description' => $current['description'],
                'sort_order' => $current['sort_order'],
                'is_visible' => $current['is_visible'],
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/categories/'.self::PUBLISHED_CATEGORY_ID,
            [
                'expected_revision' => $current['revision'],
                'slug' => 'published-category-changed',
                'name' => $current['name'],
                'description' => $current['description'],
                'sort_order' => $current['sort_order'],
                'is_visible' => $current['is_visible'],
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_PUBLISHED_REFERENCE_CONFLICT');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/categories/'.self::PUBLISHED_CATEGORY_ID.'/archive',
            ['expected_revision' => $current['revision']]
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PUBLISHED_REFERENCE_CONFLICT');

        self::assertDatabaseHas('catalog_categories', [
            'public_id' => self::PUBLISHED_CATEGORY_ID,
            'display_name' => $current['name'],
            'revision' => $current['revision'],
            'archived_at' => null,
        ]);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.published_reference_rejected',
        ]);
    }

    public function test_published_tag_allows_presentation_changes_but_protects_slug_and_archive(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $current = $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/tags/'.self::PUBLISHED_TAG_ID)
            ->assertOk()
            ->json('data');

        foreach ([
            ['name' => 'Published Tag Changed'],
            ['sort_order' => 30],
            ['is_visible' => false],
            ['is_visible' => true],
        ] as $change) {
            Auth::forgetGuards();
            $current = $this->mutatingRequest(
                $token,
                'PUT',
                '/admin/api/v2/catalog/tags/'.self::PUBLISHED_TAG_ID,
                [
                    'expected_revision' => $current['revision'],
                    'slug' => $current['slug'],
                    'name' => $current['name'],
                    'sort_order' => $current['sort_order'],
                    'is_visible' => $current['is_visible'],
                    ...$change,
                ]
            )->assertOk()->json('data');
        }

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/tags/'.self::PUBLISHED_TAG_ID,
            [
                'expected_revision' => $current['revision'],
                'slug' => 'published-tag-changed',
                'name' => $current['name'],
                'sort_order' => $current['sort_order'],
                'is_visible' => $current['is_visible'],
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_PUBLISHED_REFERENCE_CONFLICT');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/tags/'.self::PUBLISHED_TAG_ID.'/archive',
            ['expected_revision' => $current['revision']]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_PUBLISHED_REFERENCE_CONFLICT');

        self::assertDatabaseHas('catalog_tags', [
            'public_id' => self::PUBLISHED_TAG_ID,
            'display_name' => $current['name'],
            'revision' => $current['revision'],
            'archived_at' => null,
        ]);
    }

    public function test_db_guards_reject_physical_delete_and_revision_bypass(): void
    {
        $row = DB::table('catalog_categories')
            ->where('public_id', self::PUBLISHED_CATEGORY_ID)
            ->firstOrFail();
        foreach ([
            fn () => DB::table('catalog_categories')->where('id', $row->id)->delete(),
            fn () => DB::table('catalog_tags')
                ->where('public_id', self::PUBLISHED_TAG_ID)->delete(),
            fn () => DB::table('catalog_categories')->where('id', $row->id)->update([
                'display_name' => 'Bypass',
                'revision' => $row->revision,
            ]),
            fn () => DB::table('catalog_categories')->where('id', $row->id)->update([
                'slug' => 'published-slug-bypass',
                'revision' => $row->revision + 1,
            ]),
            fn () => DB::table('catalog_categories')->where('id', $row->id)->update([
                'code' => 'published-code-bypass',
                'revision' => $row->revision + 1,
            ]),
            fn () => DB::table('catalog_tags')
                ->where('public_id', self::PUBLISHED_TAG_ID)->update([
                    'code' => 'published-tag-code-bypass',
                    'revision' => DB::raw('revision + 1'),
                ]),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                DB::rollBack();
                self::fail('The Catalog database guard must reject this mutation.');
            } catch (QueryException $exception) {
                DB::rollBack();
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }
    }

    public function test_migration_rollback_restores_old_guard_and_reapply_preserves_data(): void
    {
        $migration = require database_path(
            'migrations-v2/2026_09_11_000056_'.
            'allow_v2_published_category_tag_presentation_edits.php'
        );
        $before = DB::table('catalog_categories')
            ->where('public_id', self::PUBLISHED_CATEGORY_ID)
            ->firstOrFail(['id', 'public_id', 'code', 'display_name', 'revision']);

        $migration->down();
        DB::beginTransaction();
        try {
            DB::table('catalog_categories')->where('id', $before->id)->update([
                'display_name' => 'Rollback must protect this change',
                'revision' => $before->revision + 1,
            ]);
            DB::rollBack();
            self::fail('The rollback guard must restore published presentation protection.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        } finally {
            $migration->up();
        }

        DB::table('catalog_categories')->where('id', $before->id)->update([
            'display_name' => 'Reapplied presentation change',
            'revision' => $before->revision + 1,
        ]);
        self::assertDatabaseHas('catalog_categories', [
            'id' => $before->id,
            'public_id' => $before->public_id,
            'code' => $before->code,
            'display_name' => 'Reapplied presentation change',
            'revision' => $before->revision + 1,
        ]);
    }

    public function test_http_security_and_direct_service_authorization_cannot_be_bypassed(): void
    {
        $this->postJson(
            '/admin/api/v2/catalog/categories',
            $this->categoryInput('anonymous-category'),
            ['Idempotency-Key' => 'anonymous-key']
        )->assertUnauthorized();

        $operator = $this->createAdminSession(V2AdminRole::Operator);
        $context = $this->contextForSession($operator, V2AdminRole::Operator);
        $this->expectException(V2AuthenticationException::class);
        app(V2CatalogMasterMutationService::class)->create(
            $context,
            'category',
            'direct-service-key',
            $this->categoryInput('direct-service-category')
        );
    }

    public function test_delete_mutation_endpoints_do_not_exist(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        foreach (['categories', 'tags', 'ranks'] as $resource) {
            Auth::forgetGuards();
            $this->asAdmin($token)
                ->deleteJson(
                    "/admin/api/v2/catalog/{$resource}/"
                    .'0198a001-0000-7000-8000-000000000001'
                )
                ->assertStatus(405);
        }
    }

    public function test_rate_limit_and_limiter_failure_are_fail_closed_and_audited(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $context = $this->contextForSession($token, V2AdminRole::Owner);
        $adminPublicId = $context->adminPublicId;
        $limiter = app(V2CatalogMutationRateLimiter::class);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $limiter->assertAdmin($adminPublicId);
        }
        try {
            app(V2CatalogMasterMutationService::class)->create(
                $context,
                'category',
                'rate-limited-catalog-key',
                $this->categoryInput('rate-limited-category')
            );
            self::fail('Catalog mutation rate limiting must fail closed.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame(429, $exception->status);
            self::assertNotNull($exception->retryAfterSeconds);
        }
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.rate_limited',
            'reason_code' => 'rate_limited',
        ]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('cache unavailable'));
        $this->app->instance(
            V2CatalogMutationRateLimiter::class,
            new V2CatalogMutationRateLimiter(new LaravelRateLimiter($cache))
        );
        try {
            app(V2CatalogMasterMutationService::class)->create(
                $context,
                'category',
                'limiter-failure-catalog-key',
                $this->categoryInput('limiter-failure-category')
            );
            self::fail('Catalog mutation must reject an unavailable limiter.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTH_SERVICE_UNAVAILABLE', $exception->errorCode);
            self::assertSame(503, $exception->status);
        }
        self::assertDatabaseMissing('catalog_categories', [
            'code' => 'limiter-failure-category',
        ]);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.rate_limited',
            'reason_code' => 'auth_service_unavailable',
        ]);
    }

    public function test_outbox_failure_rolls_back_catalog_idempotency_and_success_audit(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_reject_catalog_outbox()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.topic = 'catalog.change' THEN
                    RAISE EXCEPTION 'synthetic Catalog outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER v2_test_reject_catalog_outbox '.
            'BEFORE INSERT ON outbox_messages FOR EACH ROW '.
            'EXECUTE FUNCTION v2_test_reject_catalog_outbox()'
        );
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $context = $this->contextForSession($token, V2AdminRole::Admin);
        try {
            app(V2CatalogMasterMutationService::class)->create(
                $context,
                'category',
                'catalog-outbox-rollback-key',
                $this->categoryInput('catalog-outbox-rollback')
            );
            self::fail('Catalog mutation must roll back when Outbox persistence fails.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'synthetic Catalog outbox failure',
                $exception->getMessage()
            );
        } finally {
            DB::statement(
                'DROP TRIGGER IF EXISTS v2_test_reject_catalog_outbox ON outbox_messages'
            );
            DB::statement('DROP FUNCTION IF EXISTS v2_test_reject_catalog_outbox()');
        }

        self::assertDatabaseMissing('catalog_categories', [
            'code' => 'catalog-outbox-rollback',
        ]);
        self::assertDatabaseMissing('idempotency_records', [
            'scope' => 'catalog_master_mutation',
            'resource_type' => 'catalog_category',
        ]);
        self::assertDatabaseMissing('audit_logs', [
            'action_code' => 'catalog.master.created',
            'reason_code' => 'create_completed',
        ]);
        self::assertDatabaseMissing('outbox_messages', [
            'topic' => 'catalog.change',
            'event_type' => 'catalog.master.created',
        ]);
    }

    public function test_unscoped_prize_is_rejected_and_asset_mutations_remain_available(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $asset = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/presentation-assets',
            $this->assetInput('created-asset'),
            'asset-create-key'
        )->assertCreated()
            ->assertJsonMissingPath('data.storage_identifier')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.is_archived', false)
            ->json('data');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/prizes',
            $this->prizeInput('created-prize', $asset['id']),
            'prize-create-key'
        )->assertStatus(405);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/presentation-assets/'.$asset['id'],
            ['expected_revision' => 1, 'alt_text' => 'Updated Asset', 'is_public' => true],
            'asset-update-key'
        )->assertOk()
            ->assertJsonPath('data.alt_text', 'Updated Asset')
            ->assertJsonPath('data.revision', 2);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/presentation-assets/'.$asset['id'].'/archive',
            ['expected_revision' => 2],
            'asset-archive-key'
        )->assertOk()->assertJsonPath('data.is_archived', true);
    }

    public function test_published_prize_and_asset_mutation_is_rejected_without_side_effects(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/prizes/0198a001-0000-7000-8000-000000000009',
            [
                'expected_revision' => 1,
                'rank_id' => '0198a001-0000-7000-8000-000000000003',
                'presentation_asset_id' => '0198a001-0000-7000-8000-000000000007',
                'name' => 'Published Prize Changed',
                'description' => 'Rejected',
                'display_price' => 10000,
                'exchange_points' => 8000,
                'is_visible' => true,
            ]
        )->assertStatus(405);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/presentation-assets/0198a001-0000-7000-8000-000000000007',
            ['expected_revision' => 1, 'alt_text' => 'Changed', 'is_public' => true]
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PUBLISHED_REFERENCE_CONFLICT');
        self::assertDatabaseHas('catalog_prizes', [
            'public_id' => '0198a001-0000-7000-8000-000000000009',
            'display_name' => 'Fixture S景品',
            'revision' => 1,
        ]);
        self::assertDatabaseHas('catalog_presentation_assets', [
            'public_id' => '0198a001-0000-7000-8000-000000000007',
            'alt_text' => 'Fixture S景品',
            'revision' => 1,
        ]);
    }

    public function test_asset_identity_and_physical_delete_are_rejected_by_service_and_db(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/presentation-assets/0198a001-0000-7000-8000-000000000005',
            [
                'expected_revision' => 1,
                'alt_text' => 'Fixture',
                'is_public' => true,
                'public_path' => '/changed.png',
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_ASSET_IDENTITY_IMMUTABLE');

        foreach (['catalog_prizes', 'catalog_presentation_assets'] as $table) {
            $row = DB::table($table)->orderBy('id')->firstOrFail();
            DB::beginTransaction();
            try {
                DB::table($table)->where('id', $row->id)->delete();
                DB::rollBack();
                self::fail('Physical delete must be rejected.');
            } catch (QueryException $exception) {
                DB::rollBack();
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function categoryInput(string $code): array
    {
        return [
            'code' => $code,
            'slug' => $code,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'description' => 'Plain text description',
            'sort_order' => 1,
            'is_visible' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function tagInput(string $code): array
    {
        return [
            'code' => $code,
            'slug' => $code,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'sort_order' => 1,
            'is_visible' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function rankMasterInput(string $name): array
    {
        return [
            'rank_name' => $name,
            'lineup_image' => $this->rankImageInput($name.'-lineup.png'),
            'result_image' => $this->rankImageInput($name.'-result.png'),
        ];
    }

    /** @return array<string, string> */
    private function rankImageInput(string $fileName): array
    {
        return [
            'file_name' => $fileName,
            'mime_type' => 'image/png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ];
    }

    /** @return array<string, mixed> */
    private function prizeInput(string $code, ?string $assetId): array
    {
        return [
            'code' => $code,
            'rank_id' => '0198a001-0000-7000-8000-000000000004',
            'presentation_asset_id' => $assetId,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'description' => 'Plain text prize',
            'display_price' => 3000,
            'exchange_points' => 2000,
            'is_visible' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function assetInput(string $name): array
    {
        return [
            'storage_identifier' => "catalog/{$name}.png",
            'public_path' => "/assets/catalog/{$name}.png",
            'checksum_sha256' => hash('sha256', $name),
            'media_type' => 'image',
            'mime_type' => 'image/png',
            'byte_size' => 1024,
            'alt_text' => ucfirst(str_replace('-', ' ', $name)),
            'is_public' => true,
        ];
    }

    private function mutatingRequest(
        string $token,
        string $method,
        string $uri,
        array $payload,
        ?string $key = null
    ) {
        $csrf = str_repeat('a', 64);
        $request = $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ]);

        return $method === 'PUT'
            ? $request->putJson($uri, $payload)
            : $request->postJson($uri, $payload);
    }

    private function asAdmin(string $token): static
    {
        return $this
            ->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function createAdminSession(
        V2AdminRole $role,
        bool $requiresEnrollment = false
    ): string {
        $email = $role->value.'-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid catalog mutation test password'),
            'role' => $role->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => $requiresEnrollment,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]);

        return $token;
    }

    private function contextForSession(
        string $token,
        V2AdminRole $role
    ): V2AdminAuthorizationContext {
        $hash = app(V2SessionPolicy::class)->hashSessionId($token);
        $session = DB::table('admin_sessions')->where('session_id_hash', $hash)->firstOrFail();
        $admin = DB::table('admins')->where('id', $session->admin_id)->firstOrFail();

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $role,
            $hash,
            hash('sha256', $hash),
            (string) Str::uuid7()
        );
    }
}

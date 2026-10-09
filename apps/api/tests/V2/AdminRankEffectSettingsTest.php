<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class AdminRankEffectSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Storage::fake('local');
        config([
            'filesystems.default' => 'local',
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_rank_effect_material_is_a_relation_free_asset_registry(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $key = 'rank-effect-create-'.Str::uuid7();
        $payload = [
            'title' => '当選演出',
            'asset_type' => 'image',
            'is_active' => true,
            ...$this->imageInput(),
        ];

        $created = $this->mutate(
            $token,
            'POST',
            '/admin/api/v2/catalog/rank-effects',
            $payload,
            $key
        )->assertCreated()
            ->assertJsonMissingPath('data.rank_assignments')
            ->assertJsonMissingPath('data.storage_identifier')
            ->json('data');

        Auth::forgetGuards();
        $this->mutate(
            $token,
            'POST',
            '/admin/api/v2/catalog/rank-effects',
            $payload,
            $key
        )->assertCreated()->assertJsonPath('idempotent_replay', true);

        self::assertDatabaseCount('catalog_presentation_assets', 1);
        self::assertDatabaseCount('catalog_rank_effect_materials', 1);
        self::assertDatabaseCount('catalog_rank_assets', 0);

        Auth::forgetGuards();
        $this->asAdmin($token)->getJson('/admin/api/v2/catalog/rank-effects')
            ->assertOk()
            ->assertJsonPath('items.0.id', $created['id'])
            ->assertJsonMissingPath('items.0.rank_assignments');

        Auth::forgetGuards();
        $updated = $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/rank-effects/'.$created['id'],
            [
                'expected_revision' => 1,
                'title' => '当選演出 更新',
                'asset_type' => 'image',
                'is_active' => false,
            ]
        )->assertOk()
            ->assertJsonMissingPath('data.rank_assignments')
            ->json('data');

        self::assertSame($created['id'], $updated['id']);
        self::assertFalse($updated['is_public']);
        self::assertDatabaseCount('catalog_rank_assets', 0);
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson('/admin/api/v2/catalog/rank-effects?visibility=visible')
            ->assertOk()->assertJsonCount(0, 'items');
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson('/admin/api/v2/catalog/rank-effects?visibility=hidden')
            ->assertOk()->assertJsonPath('items.0.id', $created['id']);
    }

    public function test_video_replacement_keeps_old_asset_but_never_creates_rank_assignment(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $created = $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects', [
            'title' => '動画演出',
            'asset_type' => 'video',
            'is_active' => true,
            ...$this->videoInput(),
        ])->assertCreated()
            ->assertJsonPath('data.media_type', 'video')
            ->assertJsonMissingPath('data.rank_assignments')
            ->json('data');

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->get('/admin/api/v2/catalog/presentation-assets/'.$created['id'].'/content')
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');

        Auth::forgetGuards();
        $replacement = $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/rank-effects/'.$created['id'],
            [
                'expected_revision' => 1,
                'title' => '画像へ差し替え',
                'asset_type' => 'image',
                'is_active' => true,
                ...$this->imageInput(),
            ]
        )->assertOk()
            ->assertJsonPath('data.media_type', 'image')
            ->assertJsonMissingPath('data.rank_assignments')
            ->json('data');

        self::assertNotSame($created['id'], $replacement['id']);
        self::assertDatabaseHas('catalog_presentation_assets', ['public_id' => $created['id']]);
        self::assertDatabaseCount('catalog_presentation_assets', 2);
        self::assertDatabaseCount('catalog_rank_effect_materials', 1);
        self::assertDatabaseCount('catalog_rank_assets', 0);
        self::assertDatabaseHas('catalog_rank_effect_materials', [
            'presentation_asset_id' => DB::table('catalog_presentation_assets')
                ->where('public_id', $replacement['id'])->value('id'),
        ]);

        Auth::forgetGuards();
        $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects', [
            'title' => '不正Asset',
            'asset_type' => 'image',
            'is_active' => true,
            'file_name' => 'bad.svg',
            'mime_type' => 'image/svg+xml',
            'content_base64' => base64_encode('<svg/>'),
        ])->assertUnprocessable();
    }

    public function test_operator_mutation_is_forbidden_and_delete_route_is_absent(): void
    {
        $operator = $this->createAdminSession(V2AdminRole::Operator);
        $this->mutate($operator, 'POST', '/admin/api/v2/catalog/rank-effects', [
            'title' => '拒否',
            'asset_type' => 'image',
            'is_active' => true,
            ...$this->imageInput(),
        ])->assertForbidden();

        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route): bool => str_starts_with(
                $route->uri(),
                'admin/api/v2/catalog/rank-effects'
            ));
        self::assertCount(4, $routes);
        self::assertFalse($routes->contains(fn ($route): bool => in_array(
            'DELETE',
            $route->methods(),
            true
        )));
    }

    public function test_rank_video_boundary_filters_history_and_rejects_images(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $image = ['title' => 'Historical image', 'asset_type' => 'image', 'is_active' => true, ...$this->imageInput()];
        $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects', $image)->assertCreated();
        $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects?media_type=video', $image)->assertUnprocessable();
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson('/admin/api/v2/catalog/rank-effects?media_type=video')
            ->assertOk()->assertJsonCount(0, 'items');
        self::assertDatabaseCount('catalog_rank_effect_materials', 1);
    }

    public function test_global_default_requires_explicit_unset_and_protects_every_asset_mutation(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $videos = [];
        foreach (['A', 'B'] as $title) {
            Auth::forgetGuards();
            $videos[] = $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects?media_type=video', [
                'title' => $title, 'asset_type' => 'video', 'is_active' => true, ...$this->videoInput(),
            ])->assertCreated()->assertJsonPath('data.is_default', false)->json('data');
        }
        $update = function (array $video, array $changes = []) use ($token) {
            Auth::forgetGuards();
            return $this->mutate($token, 'PUT', '/admin/api/v2/catalog/rank-effects/'.$video['id'].'?media_type=video', [
                'title' => $video['alt_text'], 'asset_type' => 'video', 'is_active' => true,
                'expected_revision' => $video['revision'], ...$changes,
            ]);
        };
        $first = $update($videos[0], ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true)->json('data');
        $update($videos[1], ['is_default' => true])->assertConflict();
        $update($first, ['is_active' => false])->assertConflict();
        $update($first, ['is_default' => false, 'is_active' => false])->assertConflict();
        $update($first, $this->videoInput())->assertConflict();
        foreach (['PUT', 'POST'] as $method) {
            Auth::forgetGuards();
            $this->mutate($token, $method, '/admin/api/v2/catalog/presentation-assets/'.$first['id'].($method === 'POST' ? '/archive' : ''),
                $method === 'POST' ? ['expected_revision' => $first['revision']]
                    : ['expected_revision' => $first['revision'], 'alt_text' => 'A', 'is_public' => false])->assertConflict();
        }
        $first = $update($first, ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.is_default', true)->json('data');
        $update($videos[0], ['is_default' => false])->assertConflict();
        $first = $update($first, ['is_default' => false])->assertOk()->assertJsonPath('data.is_default', false)->json('data');
        self::assertSame(0, DB::table('catalog_presentation_assets')->where('is_default_rank_video', true)->count());
        $replacement = $update($first, $this->videoInput())->assertOk()->assertJsonPath('data.is_default', false)->json('data');
        self::assertNotSame($first['id'], $replacement['id']);
        $update($videos[1], ['is_default' => true])->assertOk();
        self::assertSame(1, DB::table('catalog_presentation_assets')->where('is_default_rank_video', true)->count());
        $audit = DB::table('audit_logs')->where('action_code', 'catalog.rank_video.default_updated')->get();
        self::assertCount(3, $audit);
        self::assertStringContainsString('before_default', $audit[0]->metadata_redacted);
        self::assertStringContainsString('after_default', $audit[0]->metadata_redacted);
    }

    public function test_default_database_constraints_reject_second_or_unusable_video(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $identifiers = [];
        foreach (['video', 'video', 'image'] as $type) {
            Auth::forgetGuards();
            $identifiers[] = $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects', [
                'title' => $type, 'asset_type' => $type, 'is_active' => true,
                ...($type === 'video' ? $this->videoInput() : $this->imageInput()),
            ])->assertCreated()->json('data.id');
        }
        DB::table('catalog_presentation_assets')->where('public_id', $identifiers[0])->update(['is_default_rank_video' => true, 'revision' => DB::raw('revision + 1')]);
        foreach ([
            [$identifiers[1], ['is_default_rank_video' => true]],
            [$identifiers[2], ['is_default_rank_video' => true]],
            [$identifiers[0], ['is_public' => false]],
            [$identifiers[0], ['archived_at' => now()]],
        ] as [$identifier, $changes]) {
            DB::beginTransaction();
            try {
                DB::table('catalog_presentation_assets')->where('public_id', $identifier)->update([...$changes, 'revision' => DB::raw('revision + 1')]);
                self::fail('The database accepted an invalid default.');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertContains($exception->errorInfo[0], ['23505', '23514']);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_s3_fake_uploads_preserve_bytes_metadata_and_private_cdn_preview(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3', 'v2_assets.public_base_url' => 'https://cdn.example.test']);
        $token = $this->createAdminSession(V2AdminRole::Owner);
        foreach (['image' => $this->imageInput(), 'video' => $this->videoInput()] as $type => $input) {
            Auth::forgetGuards();
            $created = $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects', [
                'title' => 'CDN '.$type, 'asset_type' => $type, 'is_active' => false, ...$input,
            ])->assertCreated()->assertJsonPath('data.is_public', false)->json('data');
            $row = DB::table('catalog_presentation_assets')->where('public_id', $created['id'])->firstOrFail();
            $bytes = base64_decode($input['content_base64'], true);
            self::assertStringStartsWith('admin-assets/rank-effects/', $row->storage_identifier);
            self::assertSame($bytes, Storage::disk('s3')->get($row->storage_identifier));
            self::assertSame(strlen($bytes), (int) $row->byte_size);
            self::assertSame(hash('sha256', $bytes), $row->checksum_sha256);
            self::assertSame($input['mime_type'], $row->mime_type);
            self::assertSame(substr($row->storage_identifier, strlen('admin-assets')), $created['public_path']);
            self::assertSame($created['public_path'], $created['content_path']);
            self::assertStringStartsWith('/admin/api/', $row->public_path);
            Storage::disk('local')->assertMissing($row->storage_identifier);
            Auth::forgetGuards();
            $this->asAdmin($token)->get('/admin/api/v2/catalog/presentation-assets/'.$created['id'].'/content')
                ->assertNotFound();
        }
    }

    private function imageInput(): array
    {
        return [
            'file_name' => 'effect.png',
            'mime_type' => 'image/png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ];
    }

    /** @return array<string, string> */
    private function videoInput(): array
    {
        return [
            'file_name' => 'effect.mp4',
            'mime_type' => 'video/mp4',
            'content_base64' => base64_encode(hex2bin(
                '00000018667479706d703432000000006d70343269736f6d'
            )),
        ];
    }

    private function mutate(
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
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function createAdminSession(V2AdminRole $role): string
    {
        $email = $role->value.'-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid rank effect password'),
            'role' => $role->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]));

        return $token;
    }
}

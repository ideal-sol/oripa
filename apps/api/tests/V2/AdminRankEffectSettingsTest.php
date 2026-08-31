<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /** @return array<string, string> */
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
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]);

        return $token;
    }
}

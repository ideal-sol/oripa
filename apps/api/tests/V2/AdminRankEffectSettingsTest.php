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

    public function test_owner_uploads_lists_and_updates_rank_effect_without_replacing_file(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $rank = $this->mutate($token, 'POST', '/admin/api/v2/catalog/ranks', [
            'code' => 'effect-rank',
            'name' => '演出ランク',
            'sort_order' => 10,
            'is_visible' => true,
        ])->assertCreated()->json('data');

        Auth::forgetGuards();
        $key = 'rank-effect-create-'.Str::uuid7();
        $payload = [
            'title' => '当選演出',
            'asset_type' => 'image',
            'rank_assignments' => [['rank_id' => $rank['id'], 'sort_order' => 3]],
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
            ->assertJsonPath('data.rank_assignments.0.rank.id', $rank['id'])
            ->assertJsonPath('data.rank_assignments.0.sort_order', 3)
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

        Auth::forgetGuards();
        $this->asAdmin($token)->getJson('/admin/api/v2/catalog/rank-effects')
            ->assertOk()
            ->assertJsonPath('items.0.id', $created['id']);

        Auth::forgetGuards();
        $updated = $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/rank-effects/'.$created['id'],
            [
                'expected_revision' => 1,
                'title' => '当選演出 更新',
                'asset_type' => 'image',
                'rank_assignments' => [['rank_id' => $rank['id'], 'sort_order' => 8]],
                'is_active' => false,
            ]
        )->assertOk()->json('data');
        self::assertSame($created['id'], $updated['id']);
        self::assertSame(8, $updated['rank_assignments'][0]['sort_order']);
        self::assertFalse($updated['is_public']);
        self::assertDatabaseCount('catalog_presentation_assets', 1);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'catalog.master.created']);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'catalog.master.updated']);
    }

    public function test_video_upload_replacement_and_invalid_mime_are_enforced(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $rank = $this->mutate($token, 'POST', '/admin/api/v2/catalog/ranks', [
            'code' => 'video-rank',
            'name' => '動画ランク',
            'sort_order' => 20,
            'is_visible' => true,
        ])->assertCreated()->json('data');
        Auth::forgetGuards();
        $created = $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects', [
            'title' => '動画演出',
            'asset_type' => 'video',
            'rank_assignments' => [['rank_id' => $rank['id'], 'sort_order' => 0]],
            'is_active' => true,
            'file_name' => 'effect.mp4',
            'mime_type' => 'video/mp4',
            'content_base64' => base64_encode(hex2bin(
                '00000018667479706d703432000000006d70343269736f6d'
            )),
        ])->assertCreated()->assertJsonPath('data.media_type', 'video')->json('data');

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->get('/admin/api/v2/catalog/presentation-assets/'.$created['id'].'/content')
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');

        Auth::forgetGuards();
        $replacement = $this->mutate($token, 'PUT', '/admin/api/v2/catalog/rank-effects/'.$created['id'], [
            'expected_revision' => 1,
            'title' => '画像へ差し替え',
            'asset_type' => 'image',
            'rank_assignments' => [['rank_id' => $rank['id'], 'sort_order' => 1]],
            'is_active' => true,
            ...$this->imageInput(),
        ])->assertOk()->assertJsonPath('data.media_type', 'image')->json('data');
        self::assertNotSame($created['id'], $replacement['id']);
        self::assertDatabaseHas('catalog_presentation_assets', ['public_id' => $created['id']]);
        self::assertDatabaseCount('catalog_presentation_assets', 2);

        Auth::forgetGuards();
        $this->mutate($token, 'POST', '/admin/api/v2/catalog/rank-effects', [
            'title' => '不正Asset',
            'asset_type' => 'image',
            'rank_assignments' => [['rank_id' => $rank['id'], 'sort_order' => 0]],
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
            'rank_assignments' => [[
                'rank_id' => (string) Str::uuid7(),
                'sort_order' => 0,
            ]],
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

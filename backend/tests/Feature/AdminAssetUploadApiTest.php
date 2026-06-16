<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAssetUploadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_image_asset(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        $this->actingAdmin();

        $response = $this->postJson('/admin/api/assets/images', [
            'context' => 'prize',
            'image' => $this->fakePng('prize.png'),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.url', fn (string $url): bool => str_contains($url, '/api/assets/admin-assets/prize/'))
            ->assertJsonPath('data.path', fn (string $path): bool => str_starts_with($path, 'admin-assets/prize/'));

        Storage::disk('s3')->assertExists($response->json('data.path'));
    }

    public function test_admin_can_upload_video_asset(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        $this->actingAdmin();

        $response = $this->postJson('/admin/api/assets/videos', [
            'context' => 'draw-video',
            'video' => UploadedFile::fake()->create('rank-s.mp4', 1024, 'video/mp4'),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.url', fn (string $url): bool => str_contains($url, '/api/assets/admin-assets/draw-video/'))
            ->assertJsonPath('data.path', fn (string $path): bool => str_starts_with($path, 'admin-assets/draw-video/'));

        Storage::disk('s3')->assertExists($response->json('data.path'));
    }

    public function test_user_token_cannot_upload_admin_asset(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/admin/api/assets/images', [
            'context' => 'gacha',
            'image' => $this->fakePng('gacha.png'),
        ])->assertForbidden();
    }

    public function test_public_asset_route_streams_stored_asset(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        Storage::disk('s3')->put('admin-assets/gacha/test.txt', 'asset-body');

        $this->get('/api/assets/admin-assets/gacha/test.txt')
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSee('asset-body');
    }

    private function actingAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }

    private function fakePng(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='),
        );
    }
}

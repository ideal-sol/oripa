<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\TopBanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTopBannerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_top_banners(): void
    {
        $this->actingAdmin();
        $banner = TopBanner::factory()->create([
            'image_url' => 'https://example.test/banner-a.png',
            'link_url' => 'https://example.test/gachas/1',
            'sort_order' => 1,
        ]);

        $this->getJson('/admin/api/top-banners')
            ->assertOk()
            ->assertJsonPath('data.0.id', $banner->id)
            ->assertJsonPath('data.0.image_url', 'https://example.test/banner-a.png')
            ->assertJsonPath('data.0.link_url', 'https://example.test/gachas/1');
    }

    public function test_admin_can_create_top_banner_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->postJson('/admin/api/top-banners', [
            'image_url' => 'https://example.test/banner.png',
            'link_url' => '/gachas/1',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.image_url', 'https://example.test/banner.png')
            ->assertJsonPath('data.link_url', '/gachas/1')
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('data.is_active', true);

        $banner = TopBanner::query()->where('image_url', 'https://example.test/banner.png')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.top_banner.created',
            'auditable_type' => TopBanner::class,
            'auditable_id' => $banner->id,
        ]);
    }

    public function test_admin_can_update_top_banner_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $banner = TopBanner::factory()->create([
            'image_url' => 'https://example.test/old.png',
            'link_url' => '/old',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->putJson("/admin/api/top-banners/{$banner->id}", [
            'image_url' => 'https://example.test/new.png',
            'link_url' => '/new',
            'sort_order' => 5,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.image_url', 'https://example.test/new.png')
            ->assertJsonPath('data.link_url', '/new')
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.top_banner.updated',
            'auditable_type' => TopBanner::class,
            'auditable_id' => $banner->id,
        ]);
    }

    public function test_admin_can_bulk_update_top_banner_statuses(): void
    {
        $admin = $this->actingAdmin();
        $bannerA = TopBanner::factory()->create(['is_active' => false]);
        $bannerB = TopBanner::factory()->create(['is_active' => false]);
        $untouched = TopBanner::factory()->create(['is_active' => false]);

        $this->patchJson('/admin/api/top-banners/status', [
            'ids' => [$bannerA->id, $bannerB->id],
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.1.is_active', true);

        $this->assertDatabaseHas('top_banners', [
            'id' => $bannerA->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('top_banners', [
            'id' => $bannerB->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('top_banners', [
            'id' => $untouched->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.top_banner.bulk_status_updated',
        ]);
    }

    public function test_user_token_cannot_create_top_banner(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/admin/api/top-banners', [
            'image_url' => 'https://example.test/banner.png',
            'link_url' => '/gachas/1',
            'sort_order' => 1,
            'is_active' => true,
        ])
            ->assertForbidden();
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
}

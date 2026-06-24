<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\GachaTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGachaTagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_tags(): void
    {
        $this->actingAdmin();
        $tag = GachaTag::factory()->create([
            'name' => '高還元',
            'slug' => 'high-return',
            'sort_order' => 1,
        ]);

        $this->getJson('/admin/api/gacha-tags')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tag->id)
            ->assertJsonPath('data.0.name', '高還元')
            ->assertJsonPath('data.0.slug', 'high-return');
    }

    public function test_admin_can_show_tag(): void
    {
        $this->actingAdmin();
        $tag = GachaTag::factory()->create([
            'name' => '限定',
            'slug' => 'limited',
        ]);

        $this->getJson("/admin/api/gacha-tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tag->id)
            ->assertJsonPath('data.name', '限定');
    }

    public function test_admin_can_create_tag_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->postJson('/admin/api/gacha-tags', [
            'name' => '初心者向け',
            'slug' => 'beginner',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', '初心者向け')
            ->assertJsonPath('data.slug', 'beginner')
            ->assertJsonPath('data.sort_order', 2)
            ->assertJsonPath('data.is_active', true);

        $tag = GachaTag::query()->where('slug', 'beginner')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_tag.created',
            'auditable_type' => GachaTag::class,
            'auditable_id' => $tag->id,
        ]);
    }

    public function test_tag_slug_must_be_unique(): void
    {
        $this->actingAdmin();
        GachaTag::factory()->create(['slug' => 'pickup']);

        $this->postJson('/admin/api/gacha-tags', [
            'name' => 'Pickup 2',
            'slug' => 'pickup',
            'sort_order' => 3,
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_admin_can_update_tag_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $tag = GachaTag::factory()->create([
            'name' => 'Old',
            'slug' => 'old',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->putJson("/admin/api/gacha-tags/{$tag->id}", [
            'name' => 'New',
            'slug' => 'new',
            'sort_order' => 5,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.slug', 'new')
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_tag.updated',
            'auditable_type' => GachaTag::class,
            'auditable_id' => $tag->id,
        ]);
    }

    public function test_user_token_cannot_create_tag(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/admin/api/gacha-tags', [
            'name' => 'Pickup',
            'slug' => 'pickup',
            'sort_order' => 2,
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

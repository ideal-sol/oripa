<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\GachaCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGachaCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_show_category(): void
    {
        $this->actingAdmin();
        $category = GachaCategory::factory()->create([
            'name' => 'Show Category',
            'slug' => 'show-category',
        ]);

        $this->getJson("/admin/api/gacha-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'Show Category')
            ->assertJsonPath('data.slug', 'show-category');
    }

    public function test_admin_can_create_category_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->postJson('/admin/api/gacha-categories', [
            'name' => 'Pokemon',
            'slug' => 'pokemon',
            'sort_order' => 2,
            'is_visible' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pokemon')
            ->assertJsonPath('data.slug', 'pokemon')
            ->assertJsonPath('data.sort_order', 2)
            ->assertJsonPath('data.is_visible', true);

        $category = GachaCategory::query()->where('slug', 'pokemon')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_category.created',
            'auditable_type' => GachaCategory::class,
            'auditable_id' => $category->id,
        ]);
    }

    public function test_category_slug_must_be_unique(): void
    {
        $this->actingAdmin();
        GachaCategory::factory()->create(['slug' => 'pokemon']);

        $this->postJson('/admin/api/gacha-categories', [
            'name' => 'Pokemon 2',
            'slug' => 'pokemon',
            'sort_order' => 3,
            'is_visible' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_admin_can_update_category_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $category = GachaCategory::factory()->create([
            'name' => 'Old',
            'slug' => 'old',
            'sort_order' => 1,
            'is_visible' => true,
        ]);

        $this->putJson("/admin/api/gacha-categories/{$category->id}", [
            'name' => 'New',
            'slug' => 'new',
            'sort_order' => 5,
            'is_visible' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.slug', 'new')
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.is_visible', false);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_category.updated',
            'auditable_type' => GachaCategory::class,
            'auditable_id' => $category->id,
        ]);
    }

    public function test_user_token_cannot_create_category(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/admin/api/gacha-categories', [
            'name' => 'Pokemon',
            'slug' => 'pokemon',
            'sort_order' => 2,
            'is_visible' => true,
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

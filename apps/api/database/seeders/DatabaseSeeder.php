<?php

namespace Database\Seeders;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\Enums\ProbabilityMode;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaCategory;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = AdminUser::query()->updateOrCreate(
            ['email' => 'admin@example.local'],
            [
                'name' => 'Luxe Pack Admin',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ],
        );

        AdminUser::query()->updateOrCreate(
            ['email' => 'admin@luxe-pack.biz'],
            [
                'name' => 'Luxe Pack Admin',
                'email_verified_at' => now(),
                'password' => Hash::make('LuxePack-Admin-2026'),
                'role' => 'admin',
                'is_active' => true,
            ],
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'user@example.local'],
            [
                'name' => 'Luxe Pack User',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'status' => 'active',
            ],
        );

        Wallet::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['paid_balance' => 0, 'free_balance' => 0],
        );

        $category = GachaCategory::query()->updateOrCreate(
            ['slug' => 'apple'],
            [
                'name' => 'Apple',
                'sort_order' => 1,
                'is_visible' => true,
            ],
        );

        $gacha = Gacha::query()->updateOrCreate(
            ['slug' => 'apple-oripa-local'],
            [
                'category_id' => $category->id,
                'title' => 'Apple オリパ',
                'status' => GachaStatus::Draft->value,
                'price' => 500,
                'total_count' => 10000,
                'sold_count' => 0,
                'probability_mode' => ProbabilityMode::Single->value,
                'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
                'minimum_guarantee_value' => 10,
                'minimum_guarantee_cost' => 10,
                'start_at' => now(),
                'end_at' => now()->addMonth(),
                'description' => 'Local development seed gacha.',
                'caution' => '確率式。最低保証枠があります。',
                'main_image_url' => 'https://example.test/images/gacha.png',
                'target_margin' => 30.00,
            ],
        );

        $rankS = GachaRank::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_key' => 'S'],
            [
                'display_name' => 'ランクS',
                'sort_order' => 1,
                'is_visible' => true,
            ],
        );

        $rankA = GachaRank::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_key' => 'A'],
            [
                'display_name' => 'ランクA',
                'sort_order' => 2,
                'is_visible' => true,
            ],
        );

        $rankB = GachaRank::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_key' => 'B'],
            [
                'display_name' => 'ランクB',
                'sort_order' => 3,
                'is_visible' => true,
            ],
        );

        $prizeS = GachaPrize::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_id' => $rankS->id, 'name' => 'iPhone 17 Pro'],
            [
                'image_url' => 'https://example.test/images/prize.png',
                'max_win_count' => 1,
                'won_count' => 0,
                'cost_price' => 180000,
                'display_price' => 220000,
                'exchange_point' => 10000,
                'condition' => '新品',
                'is_active' => true,
                'is_visible' => true,
                'sort_order' => 1,
            ],
        );

        $prizeA = GachaPrize::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_id' => $rankA->id, 'name' => 'Apple Watch'],
            [
                'image_url' => 'https://example.test/images/prize.png',
                'max_win_count' => 10,
                'won_count' => 0,
                'cost_price' => 45000,
                'display_price' => 60000,
                'exchange_point' => 3000,
                'condition' => '新品',
                'is_active' => true,
                'is_visible' => true,
                'sort_order' => 1,
            ],
        );

        $prizeB = GachaPrize::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_id' => $rankB->id, 'name' => 'AirTag'],
            [
                'image_url' => 'https://example.test/images/prize.png',
                'max_win_count' => 200,
                'won_count' => 0,
                'cost_price' => 4000,
                'display_price' => 6000,
                'exchange_point' => 500,
                'condition' => '新品',
                'is_active' => true,
                'is_visible' => true,
                'sort_order' => 1,
            ],
        );

        if (! $gacha->current_probability_version_id) {
            app(ProbabilityVersionPublisher::class)->publish(
                $gacha,
                [
                    [
                        'stage_key' => 'stage_1',
                        'name' => 'デフォルトステージ',
                        'min_draw_number' => 1,
                        'max_draw_number' => null,
                        'sort_order' => 1,
                        'probabilities' => [
                            ['prize_id' => $prizeS->id, 'probability_ppm' => 1_000],
                            ['prize_id' => $prizeA->id, 'probability_ppm' => 10_000],
                            ['prize_id' => $prizeB->id, 'probability_ppm' => 100_000],
                            ['is_minimum_guarantee' => true, 'probability_ppm' => 889_000],
                        ],
                    ],
                ],
                $admin,
                'Local development seed probability version.'
            );
        }
    }
}

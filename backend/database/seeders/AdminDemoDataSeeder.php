<?php

namespace Database\Seeders;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\Enums\ProbabilityMode;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Announcement;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaCategory;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\Payment;
use App\Models\PointAdjustment;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\ShippingItem;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\UserPrize;
use App\Models\UserProfile;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Admin demo data seeding is disabled in production.');
        }

        DB::transaction(function (): void {
            $admin = $this->admin();
            $users = $this->users();
            [$gacha, $prizes] = $this->gacha($admin);

            $this->payments($users);
            $this->points($users, $admin);
            $this->announcements();
            $drawResults = $this->draws($users, $gacha, $prizes);
            $userPrizes = $this->userPrizes($drawResults);
            $this->shipping($users[0], $userPrizes);
        });
    }

    private function announcements(): void
    {
        $items = [
            ['Luxe Pack 管理データ連携を開始しました', '管理画面で登録した公開中ガチャとお知らせがトップへ反映されます。'],
            ['配送申請と景品箱の表示確認ができます', '景品箱、配送申請、追跡番号の表示確認用データを投入しています。'],
            ['ポイント管理方針について', '有償ポイントは期限なし、無償ポイントのみ期限ありで管理します。'],
        ];

        foreach ($items as $index => [$title, $body]) {
            Announcement::query()->updateOrCreate(
                ['title' => $title],
                [
                    'body' => $body,
                    'status' => 'published',
                    'published_at' => now()->subDays($index),
                ],
            );
        }
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->updateOrCreate(
            ['email' => 'admin@luxe-pack.biz'],
            [
                'name' => 'Luxe Pack Admin',
                'email_verified_at' => now(),
                'password' => Hash::make('LuxePack-Admin-2026'),
                'role' => 'admin',
                'is_active' => true,
            ],
        );
    }

    /**
     * @return list<User>
     */
    private function users(): array
    {
        $payloads = [
            ['name' => '山田 太郎', 'email' => 'demo-user-1@luxe-pack.local', 'status' => 'active', 'last' => '山田', 'first' => '太郎'],
            ['name' => '佐藤 花子', 'email' => 'demo-user-2@luxe-pack.local', 'status' => 'active', 'last' => '佐藤', 'first' => '花子'],
            ['name' => '鈴木 一郎', 'email' => 'demo-user-3@luxe-pack.local', 'status' => 'active', 'last' => '鈴木', 'first' => '一郎'],
        ];

        return array_map(function (array $payload): User {
            $user = User::query()->updateOrCreate(
                ['email' => $payload['email']],
                [
                    'name' => $payload['name'],
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                    'status' => $payload['status'],
                ],
            );

            UserProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'last_name' => $payload['last'],
                    'first_name' => $payload['first'],
                    'last_name_kana' => 'ヤマダ',
                    'first_name_kana' => 'タロウ',
                    'postal_code' => '100-0001',
                    'prefecture' => '東京都',
                    'city' => '千代田区',
                    'address_line1' => '千代田1-1',
                    'address_line2' => 'Luxe Pack Demo',
                    'phone_number' => '0312345678',
                    'birth_date' => '1990-01-01',
                ],
            );

            Wallet::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['paid_balance' => 12000, 'free_balance' => 3000],
            );

            return $user;
        }, $payloads);
    }

    /**
     * @return array{0: Gacha, 1: array<string, GachaPrize>}
     */
    private function gacha(AdminUser $admin): array
    {
        $categoryPremium = GachaCategory::query()->updateOrCreate(
            ['slug' => 'demo-premium'],
            ['name' => 'プレミアム', 'sort_order' => 10, 'is_visible' => true],
        );
        GachaCategory::query()->updateOrCreate(
            ['slug' => 'demo-standard'],
            ['name' => 'スタンダード', 'sort_order' => 20, 'is_visible' => true],
        );

        $gacha = Gacha::query()->updateOrCreate(
            ['slug' => 'demo-luxe-pack-premium'],
            [
                'category_id' => $categoryPremium->id,
                'title' => 'デモ プレミアムオリパ',
                'status' => GachaStatus::Active->value,
                'price' => 500,
                'total_count' => 1000,
                'probability_mode' => ProbabilityMode::Single->value,
                'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
                'minimum_guarantee_value' => 10,
                'minimum_guarantee_cost' => 10,
                'start_at' => now()->subDay(),
                'end_at' => now()->addMonth(),
                'description' => '管理画面確認用のデモガチャです。',
                'caution' => 'デモデータです。実販売には使用しないでください。',
                'main_image_url' => 'https://placehold.co/960x540/png?text=Luxe+Pack+Demo',
                'show_on_top_slider' => true,
                'target_margin' => 30,
            ],
        );

        Gacha::query()->updateOrCreate(
            ['slug' => 'demo-draft-check'],
            [
                'category_id' => $categoryPremium->id,
                'title' => 'デモ 下書きガチャ',
                'status' => GachaStatus::Draft->value,
                'price' => 300,
                'total_count' => 500,
                'sold_count' => 0,
                'probability_mode' => ProbabilityMode::Single->value,
                'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
                'minimum_guarantee_value' => 5,
                'minimum_guarantee_cost' => 5,
                'start_at' => now(),
                'end_at' => now()->addWeeks(2),
                'description' => '下書き表示確認用。',
                'caution' => 'デモデータです。',
                'main_image_url' => 'https://placehold.co/960x540/png?text=Draft+Gacha',
                'target_margin' => 25,
            ],
        );

        Gacha::query()->updateOrCreate(
            ['slug' => 'demo-sneaker-box'],
            [
                'category_id' => $categoryPremium->id,
                'title' => 'デモ スニーカーBOX',
                'status' => GachaStatus::Active->value,
                'price' => 800,
                'total_count' => 1500,
                'sold_count' => 320,
                'probability_mode' => ProbabilityMode::Single->value,
                'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
                'minimum_guarantee_value' => 20,
                'minimum_guarantee_cost' => 20,
                'start_at' => now()->subHours(8),
                'end_at' => now()->addWeeks(3),
                'description' => 'トップ一覧レイアウト確認用の公開中ガチャです。',
                'caution' => 'デモデータです。',
                'main_image_url' => 'https://placehold.co/960x540/111827/ffffff/png?text=Sneaker+Box',
                'show_on_top_slider' => true,
                'target_margin' => 28,
            ],
        );

        Gacha::query()->updateOrCreate(
            ['slug' => 'demo-standard-pack'],
            [
                'category_id' => $categoryPremium->id,
                'title' => 'デモ スタンダードパック',
                'status' => GachaStatus::Active->value,
                'price' => 300,
                'total_count' => 2000,
                'sold_count' => 860,
                'probability_mode' => ProbabilityMode::Single->value,
                'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
                'minimum_guarantee_value' => 5,
                'minimum_guarantee_cost' => 5,
                'start_at' => now()->subHours(4),
                'end_at' => now()->addWeeks(2),
                'description' => '開催中ガチャのカード確認用データです。',
                'caution' => 'デモデータです。',
                'main_image_url' => 'https://placehold.co/960x540/f8fafc/111827/png?text=Standard+Pack',
                'show_on_top_slider' => false,
                'target_margin' => 25,
            ],
        );

        Gacha::query()->updateOrCreate(
            ['slug' => 'demo-sold-out-pack'],
            [
                'category_id' => $categoryPremium->id,
                'title' => 'デモ 完売パック',
                'status' => GachaStatus::SoldOut->value,
                'price' => 1000,
                'total_count' => 800,
                'sold_count' => 800,
                'probability_mode' => ProbabilityMode::Single->value,
                'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
                'minimum_guarantee_value' => 30,
                'minimum_guarantee_cost' => 30,
                'start_at' => now()->subDays(3),
                'end_at' => now()->addDay(),
                'description' => '完売オーバーレイ確認用データです。',
                'caution' => 'デモデータです。',
                'main_image_url' => 'https://placehold.co/960x540/0f172a/ffffff/png?text=SOLD+OUT',
                'show_on_top_slider' => true,
                'target_margin' => 30,
            ],
        );

        $rankS = $this->rank($gacha, 'S', 'S賞', 1, 'https://placehold.co/320x180/png?text=S+Rank');
        $rankA = $this->rank($gacha, 'A', 'A賞', 2, 'https://placehold.co/320x180/png?text=A+Rank');
        $rankB = $this->rank($gacha, 'B', 'B賞', 3, 'https://placehold.co/320x180/png?text=B+Rank');

        $prizes = [
            'console' => $this->prize($gacha, $rankS, '最新ゲーム機セット', 1, 60000, 85000, 8000, 1),
            'watch' => $this->prize($gacha, $rankA, 'スマートウォッチ', 8, 18000, 30000, 2500, 1),
            'card' => $this->prize($gacha, $rankB, 'ギフトカード 1,000円', 120, 900, 1000, 300, 1),
        ];

        if (! $gacha->current_probability_version_id) {
            app(ProbabilityVersionPublisher::class)->publish($gacha, [[
                'stage_key' => 'stage_1',
                'name' => '通常ステージ',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'sort_order' => 1,
                'probabilities' => [
                    ['prize_id' => $prizes['console']->id, 'probability_ppm' => 1_000],
                    ['prize_id' => $prizes['watch']->id, 'probability_ppm' => 20_000],
                    ['prize_id' => $prizes['card']->id, 'probability_ppm' => 120_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 859_000],
                ],
            ]], $admin, 'Admin demo data probability version.');

            $gacha->refresh();
        }

        return [$gacha, $prizes];
    }

    private function rank(Gacha $gacha, string $key, string $name, int $sortOrder, string $imageUrl): GachaRank
    {
        return GachaRank::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_key' => $key],
            [
                'display_name' => $name,
                'description' => "{$name}のデモランクです。",
                'image_url' => $imageUrl,
                'sort_order' => $sortOrder,
                'is_visible' => true,
            ],
        );
    }

    private function prize(Gacha $gacha, GachaRank $rank, string $name, int $maxWinCount, int $cost, int $displayPrice, int $exchangePoint, int $sortOrder): GachaPrize
    {
        return GachaPrize::query()->updateOrCreate(
            ['gacha_id' => $gacha->id, 'rank_id' => $rank->id, 'name' => $name],
            [
                'image_url' => 'https://placehold.co/420x320/png?text='.rawurlencode($name),
                'max_win_count' => $maxWinCount,
                'cost_price' => $cost,
                'display_price' => $displayPrice,
                'exchange_point' => $exchangePoint,
                'condition' => '新品',
                'is_active' => true,
                'is_visible' => true,
                'sort_order' => $sortOrder,
            ],
        );
    }

    /**
     * @param list<User> $users
     */
    private function payments(array $users): void
    {
        $statuses = [
            ['pending', null, null, null],
            ['succeeded', now()->subDays(4), null, null],
            ['failed', null, null, null],
            ['canceled', null, null, null],
            ['refunded', now()->subDays(10), now()->subDays(2), null],
            ['chargeback', now()->subDays(12), null, now()->subDay()],
        ];

        foreach ($statuses as $index => [$status, $paidAt, $refundedAt, $chargebackAt]) {
            Payment::query()->updateOrCreate(
                ['provider' => 'demo', 'provider_payment_id' => 'demo-payment-'.$status],
                [
                    'user_id' => $users[$index % count($users)]->id,
                    'webhook_event_id' => 'demo-webhook-'.$status,
                    'status' => $status,
                    'amount' => 1000 * ($index + 1),
                    'paid_point_amount' => $status === 'succeeded' ? 1000 * ($index + 1) : 0,
                    'free_point_amount' => $status === 'succeeded' ? 100 : 0,
                    'currency' => 'JPY',
                    'metadata' => ['demo' => true, 'status' => $status],
                    'paid_at' => $paidAt,
                    'refunded_at' => $refundedAt,
                    'chargeback_at' => $chargebackAt,
                ],
            );
        }
    }

    /**
     * @param list<User> $users
     */
    private function points(array $users, AdminUser $admin): void
    {
        foreach ($users as $index => $user) {
            $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();

            $paidLot = PointLot::query()->updateOrCreate(
                ['user_id' => $user->id, 'source_type' => 'purchase', 'source_id' => 9000 + $index],
                [
                    'point_type' => 'paid',
                    'granted_amount' => 12000,
                    'remaining_amount' => 12000,
                    'granted_at' => now()->subDays(5),
                    'expire_at' => null,
                ],
            );

            $freeLot = PointLot::query()->updateOrCreate(
                ['user_id' => $user->id, 'source_type' => 'campaign', 'source_id' => 9100 + $index],
                [
                    'point_type' => 'free',
                    'granted_amount' => 3000,
                    'remaining_amount' => 3000,
                    'granted_at' => now()->subDays(3),
                    'expire_at' => now()->addDays(30),
                ],
            );

            PointLedger::query()->firstOrCreate(
                ['user_id' => $user->id, 'related_type' => PointLot::class, 'related_id' => $paidLot->id, 'ledger_type' => 'purchase'],
                [
                    'wallet_id' => $wallet->id,
                    'point_lot_id' => $paidLot->id,
                    'point_type' => 'paid',
                    'amount' => 12000,
                    'balance_after' => $wallet->paid_balance,
                    'description' => 'デモ購入ポイント',
                ],
            );

            PointLedger::query()->firstOrCreate(
                ['user_id' => $user->id, 'related_type' => PointLot::class, 'related_id' => $freeLot->id, 'ledger_type' => 'grant'],
                [
                    'wallet_id' => $wallet->id,
                    'point_lot_id' => $freeLot->id,
                    'point_type' => 'free',
                    'amount' => 3000,
                    'balance_after' => $wallet->free_balance,
                    'description' => 'デモキャンペーンポイント',
                ],
            );
        }

        PointAdjustment::query()->firstOrCreate(
            ['user_id' => $users[0]->id, 'adjustment_type' => 'grant', 'point_type' => 'paid', 'amount' => 1500, 'reason' => 'デモ: 有償ポイント付与'],
            ['admin_user_id' => $admin->id, 'expire_at' => null],
        );

        PointAdjustment::query()->firstOrCreate(
            ['user_id' => $users[1]->id, 'adjustment_type' => 'grant', 'point_type' => 'free', 'amount' => 800, 'reason' => 'デモ: 無償ポイント付与'],
            ['admin_user_id' => $admin->id, 'expire_at' => now()->addDays(14)],
        );

        PointAdjustment::query()->firstOrCreate(
            ['user_id' => $users[2]->id, 'adjustment_type' => 'deduct', 'point_type' => null, 'amount' => 500, 'reason' => 'デモ: ポイント減算'],
            ['admin_user_id' => $admin->id, 'expire_at' => null],
        );
    }

    /**
     * @param list<User> $users
     * @param array<string, GachaPrize> $prizes
     * @return list<DrawResult>
     */
    private function draws(array $users, Gacha $gacha, array $prizes): array
    {
        $version = $gacha->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages()->orderBy('sort_order')->firstOrFail();
        $drawResults = [];
        $requests = [
            ['user' => $users[0], 'count' => 3, 'key' => 'demo-draw-completed', 'status' => 'completed'],
            ['user' => $users[1], 'count' => 1, 'key' => 'demo-draw-processing', 'status' => 'processing'],
            ['user' => $users[2], 'count' => 1, 'key' => 'demo-draw-failed', 'status' => 'failed'],
        ];

        foreach ($requests as $requestPayload) {
            $request = DrawRequest::query()->firstOrCreate(
                [
                    'user_id' => $requestPayload['user']->id,
                    'gacha_id' => $gacha->id,
                    'idempotency_key' => $requestPayload['key'],
                ],
                [
                    'draw_count' => $requestPayload['count'],
                    'status' => $requestPayload['status'],
                    'consumed_point_total' => $gacha->price * $requestPayload['count'],
                ],
            );

            if ($request->results()->exists()) {
                array_push($drawResults, ...$request->results()->get()->all());
                continue;
            }

            if ($requestPayload['status'] === 'failed') {
                continue;
            }

            $resultPayloads = $requestPayload['key'] === 'demo-draw-completed'
                ? [
                    ['type' => 'prize', 'prize' => $prizes['console'], 'random' => 100],
                    ['type' => 'prize', 'prize' => $prizes['card'], 'random' => 100000],
                    ['type' => 'point_back', 'prize' => null, 'random' => 900000],
                ]
                : [
                    ['type' => 'point_back', 'prize' => null, 'random' => 950000],
                ];

            foreach ($resultPayloads as $payload) {
                $sequence = ((int) DrawResult::query()->where('gacha_id', $gacha->id)->max('draw_sequence_number')) + 1;
                $drawResults[] = DrawResult::query()->create([
                    'draw_request_id' => $request->id,
                    'user_id' => $requestPayload['user']->id,
                    'gacha_id' => $gacha->id,
                    'draw_sequence_number' => $sequence,
                    'rank_id' => $payload['prize']?->rank_id,
                    'prize_id' => $payload['prize']?->id,
                    'result_type' => $payload['type'],
                    'consumed_point' => $gacha->price,
                    'granted_point' => $payload['type'] === 'point_back' ? 10 : 0,
                    'random_value' => $payload['random'],
                    'probability_version_id' => $version->id,
                    'probability_version_stage_id' => $stage->id,
                ]);
            }
        }

        $gacha->forceFill([
            'sold_count' => DrawResult::query()->where('gacha_id', $gacha->id)->count(),
        ])->save();

        foreach ($prizes as $prize) {
            $prize->forceFill([
                'won_count' => DrawResult::query()->where('prize_id', $prize->id)->count(),
            ])->save();
        }

        return $drawResults;
    }

    /**
     * @param list<DrawResult> $drawResults
     * @return list<UserPrize>
     */
    private function userPrizes(array $drawResults): array
    {
        $userPrizes = [];

        foreach ($drawResults as $drawResult) {
            if ($drawResult->result_type->value !== 'prize' || ! $drawResult->prize_id) {
                continue;
            }

            $status = $drawResult->draw_sequence_number % 2 === 0 ? 'shipping_requested' : 'stored';
            $userPrizes[] = UserPrize::query()->firstOrCreate(
                ['draw_result_id' => $drawResult->id],
                [
                    'user_id' => $drawResult->user_id,
                    'gacha_id' => $drawResult->gacha_id,
                    'gacha_prize_id' => $drawResult->prize_id,
                    'status' => $status,
                    'acquired_at' => now()->subDays(2),
                    'storage_expire_at' => now()->addDays(28),
                    'converted_point' => null,
                ],
            );
        }

        return $userPrizes;
    }

    /**
     * @param list<UserPrize> $userPrizes
     */
    private function shipping(User $user, array $userPrizes): void
    {
        if ($userPrizes === []) {
            return;
        }

        foreach ($userPrizes as $userPrize) {
            if ($userPrize->user_id !== $user->id) {
                continue;
            }

            $existingItem = ShippingItem::query()->where('user_prize_id', $userPrize->id)->first();
            $shippingRequest = $existingItem?->shippingRequest ?? ShippingRequest::query()->create([
                'user_id' => $user->id,
                'status' => 'requested',
                'recipient_name' => '山田 太郎',
                'postal_code' => '100-0001',
                'prefecture' => '東京都',
                'city' => '千代田区',
                'address_line1' => '千代田1-1',
                'address_line2' => 'Luxe Pack Demo',
                'phone_number' => '0312345678',
                'tracking_number' => null,
                'requested_at' => now()->subDay(),
                'shipped_at' => null,
            ]);

            ShippingItem::query()->firstOrCreate(
                [
                    'shipping_request_id' => $shippingRequest->id,
                    'user_prize_id' => $userPrize->id,
                ],
                [
                    'status' => 'requested',
                    'tracking_number' => null,
                    'shipped_at' => null,
                ],
            );

            $userPrize->forceFill(['status' => 'shipping_requested'])->save();
        }
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Admin\Services\DailySalesReportService;
use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaProbabilityVersionStage;
use App\Models\GachaRank;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDailySalesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_previous_day_sales_report(): void
    {
        $user = User::factory()->create();
        $targetDate = CarbonImmutable::parse('2026-06-16 12:00:00', 'Asia/Tokyo');
        $gacha = Gacha::factory()->create([
            'title' => 'テストガチャ',
            'price' => 500,
            'total_count' => 100,
            'sold_count' => 3,
        ]);
        $rankS = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'display_name' => 'Sランク',
            'sort_order' => 1,
        ]);
        $rankA = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'display_name' => 'Aランク',
            'sort_order' => 2,
        ]);
        GachaPrize::factory()->forGachaAndRank($gacha, $rankS)->create([
            'max_win_count' => 5,
            'won_count' => 2,
        ]);
        GachaPrize::factory()->forGachaAndRank($gacha, $rankA)->create([
            'max_win_count' => 10,
            'won_count' => 1,
        ]);
        $probabilityVersion = GachaProbabilityVersion::query()->create([
            'gacha_id' => $gacha->id,
            'version_number' => 1,
            'status' => 'published',
            'snapshot_hash' => 'daily-report-test',
            'published_at' => $targetDate,
        ]);
        $stage = GachaProbabilityVersionStage::query()->create([
            'probability_version_id' => $probabilityVersion->id,
            'stage_key' => 'default',
            'name' => '通常',
            'condition_type' => 'sold_count',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
        ]);

        Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'daily-report-payment-1',
            'status' => PaymentStatus::Succeeded,
            'amount' => 3000,
            'paid_point_amount' => 3000,
            'free_point_amount' => 0,
            'currency' => 'JPY',
            'paid_at' => $targetDate,
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'daily-report-payment-pending',
            'status' => PaymentStatus::Pending,
            'amount' => 9999,
            'paid_point_amount' => 9999,
            'free_point_amount' => 0,
            'currency' => 'JPY',
            'paid_at' => $targetDate,
        ]);

        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 3,
            'idempotency_key' => 'daily-report-draw',
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => 1500,
        ]);
        $drawRequest->forceFill([
            'created_at' => $targetDate,
            'updated_at' => $targetDate,
        ])->save();
        DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => 1,
            'rank_id' => $rankS->id,
            'prize_id' => GachaPrize::query()->where('rank_id', $rankS->id)->value('id'),
            'result_type' => DrawResultType::Prize,
            'consumed_point' => 500,
            'granted_point' => 0,
            'random_value' => 1,
            'probability_version_id' => $probabilityVersion->id,
            'probability_version_stage_id' => $stage->id,
        ])->forceFill(['created_at' => $targetDate])->save();
        DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => 2,
            'rank_id' => $rankA->id,
            'prize_id' => GachaPrize::query()->where('rank_id', $rankA->id)->value('id'),
            'result_type' => DrawResultType::Prize,
            'consumed_point' => 500,
            'granted_point' => 0,
            'random_value' => 2,
            'probability_version_id' => $probabilityVersion->id,
            'probability_version_stage_id' => $stage->id,
        ])->forceFill(['created_at' => $targetDate])->save();

        $report = app(DailySalesReportService::class)
            ->buildPreviousDayReport(CarbonImmutable::parse('2026-06-17 10:00:00', 'Asia/Tokyo'));

        $this->assertSame('2026-06-16', $report['date']);
        $this->assertSame(3000, $report['payment_total_amount']);
        $this->assertSame(1500, $report['gacha_sales_point_total']);
        $this->assertSame(3, $report['gacha_draw_count_total']);
        $this->assertSame('テストガチャ', $report['gachas'][0]['title']);
        $this->assertSame(97, $report['gachas'][0]['remaining_draw_count']);
        $this->assertSame([
            ['id' => $rankS->id, 'display_name' => 'Sランク', 'emitted_count' => 1, 'remaining_prize_count' => 3],
            ['id' => $rankA->id, 'display_name' => 'Aランク', 'emitted_count' => 1, 'remaining_prize_count' => 9],
        ], $report['gachas'][0]['ranks']);
    }

    public function test_command_sends_report_to_discord_when_webhook_is_configured(): void
    {
        config(['services.discord.admin_webhook_url' => 'https://discord.test/webhook']);
        Http::fake([
            'discord.test/*' => Http::response('', 204),
        ]);

        $this->artisan('admin:daily-sales-report --date=2026-06-16')
            ->expectsOutputToContain('【日次売上レポート】2026-06-16')
            ->expectsOutput('Discord notification sent.')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.test/webhook'
            && str_contains($request['content'], '前日の総売上(決済)')
            && $request['allowed_mentions']['parse'] === []);
    }
}

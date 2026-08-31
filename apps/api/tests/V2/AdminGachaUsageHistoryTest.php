<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2AdminCatalogReadService;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGachaUsageHistoryTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        $catalog = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($catalog);
        $this->fixture = $this->drawFixture();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_completed_normal_requests_are_listed_once_with_stable_cursor(): void
    {
        $this->completedRequest(usedAt: '2026-08-04T01:00:00Z');
        $newer = $this->completedRequest(usedAt: '2026-08-04T02:00:00Z');
        $this->processingRequest();
        $this->qaRequest();
        $token = $this->sessionToken(V2AdminRole::Operator);

        $first = $this->asAdmin($token)->getJson(
            '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/history?limit=1'
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $newer)
            ->assertJsonPath('items.0.user.display_name', '利用ユーザー')
            ->assertJsonPath('items.0.executed_count', 5)
            ->assertJsonPath('items.0.status_summary.0.status', 'selection_pending')
            ->assertJsonPath('items.0.status_summary.0.count', 1)
            ->assertJsonPath('items.0.status_summary.1.status', 'point_exchange')
            ->assertJsonPath('items.0.status_summary.1.count', 1)
            ->assertJsonMissingPath('items.0.user.email');
        $cursor = $first->json('next_cursor');
        self::assertIsString($cursor);
        self::assertNotSame((string) $this->fixture['user_id'], $cursor);
        self::assertGreaterThan(64, strlen($cursor));

        Auth::forgetGuards();
        $second = $this->asAdmin($token)->getJson(
            '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/history?limit=1&cursor='
            .urlencode($cursor)
        )->assertOk()->json('items.0.id');
        self::assertNotSame($newer, $second);
        self::assertSame(2, DB::table('draw_requests')
            ->where('status', 'completed')->where('is_qa_draw', false)->count());
    }

    public function test_detail_returns_every_won_prize_and_no_internal_fields(): void
    {
        $requestId = $this->completedRequest();
        $response = $this->asAdmin($this->sessionToken(V2AdminRole::Owner))
            ->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/history/'.$requestId)
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.executed_count', 5)
            ->assertJsonPath('data.consumed_points', 500)
            ->assertJsonCount(2, 'data.prizes')
            ->assertJsonPath('data.prizes.0.prize_name', 'Fixture S景品')
            ->assertJsonPath('data.prizes.0.rank.name', 'Sランク')
            ->assertJsonPath('data.prizes.0.exchange_points', 8000)
            ->assertJsonPath('data.prizes.1.status', 'converted');
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach (['internal_request_id', 'user_id', 'password_hash', 'email_normalized'] as $field) {
            self::assertStringNotContainsString($field, $encoded);
        }
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_all_catalog_read_roles_are_allowed_and_authentication_and_scope_fail_closed(): void
    {
        $requestId = $this->completedRequest();
        foreach (V2AdminRole::cases() as $role) {
            Auth::forgetGuards();
            $this->asAdmin($this->sessionToken($role))
                ->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/history')
                ->assertOk();
        }
        Auth::forgetGuards();
        $this->flushHeaders()
            ->withUnencryptedCookie('__Host-oripa_admin_session', '')
            ->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/history')
            ->assertUnauthorized();
        $token = $this->sessionToken(V2AdminRole::Owner);
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson(
            '/admin/api/v2/catalog/gachas/'.Str::uuid7().'/history/'.$requestId
        )->assertNotFound()->assertJsonPath('code', 'CATALOG_RESOURCE_NOT_FOUND');
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson(
            '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/history/'.Str::uuid7()
        )->assertNotFound();
    }

    public function test_target_queries_have_constant_query_count(): void
    {
        $requestId = $this->completedRequest();
        $context = $this->adminContext();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(V2AdminCatalogReadService::class)->gachaUsageHistory(
            $context,
            self::GACHA_ID,
            ['limit' => 20]
        );
        $listQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        app(V2AdminCatalogReadService::class)->gachaUsageHistoryDetail(
            $context,
            self::GACHA_ID,
            $requestId
        );
        $detailQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertSame(4, $listQueries);
        self::assertSame(4, $detailQueries);
    }

    private function completedRequest(string $usedAt = '2026-08-04T00:00:00Z'): string
    {
        $requestId = $this->request('completed', false, $usedAt);
        for ($sequence = 1; $sequence <= 5; $sequence++) {
            $isPrize = $sequence <= 2;
            $prize = $sequence === 1 ? $this->fixture['prizes'][0] : $this->fixture['prizes'][1];
            $snapshot = $isPrize ? [
                'result_type' => 'prize',
                'rank' => $prize['rank'],
                'prize' => $prize['prize'],
                'animation' => ['image' => null, 'video' => null],
            ] : [
                'result_type' => 'point_back',
                'point_back' => ['amount' => 100, 'point_type' => 'free'],
            ];
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $resultId = DB::table('draw_results')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'draw_request_id' => DB::table('draw_requests')->where('public_id', $requestId)->value('id'),
                'user_id' => $this->fixture['user_id'],
                'gacha_draw_state_id' => $this->fixture['draw_state_id'],
                'probability_version_id' => $this->fixture['probability_id'],
                'probability_stage_id' => $this->fixture['stage_id'],
                'request_sequence' => $sequence,
                'draw_sequence_number' => DB::table('draw_results')->max('draw_sequence_number') + 1,
                'result_type' => $isPrize ? 'prize' : 'point_back',
                'gacha_version_prize_id' => $isPrize ? $prize['relation_id'] : null,
                'rank_id' => $isPrize ? $prize['rank_id'] : null,
                'consumed_points' => 100,
                'point_back_amount' => $isPrize ? 0 : 100,
                'random_value' => $sequence,
                'display_snapshot' => $encoded,
                'display_snapshot_sha256' => hash('sha256', $encoded),
                'occurred_at' => $usedAt,
                'created_at' => $usedAt,
                'is_qa_draw' => false,
                'qa_draw_plan_item_id' => null,
            ]);
            if ($isPrize) {
                DB::table('user_prizes')->insert([
                    'public_id' => (string) Str::uuid7(),
                    'user_id' => $this->fixture['user_id'],
                    'draw_result_id' => $resultId,
                    'gacha_version_prize_id' => $prize['relation_id'],
                    'status' => $sequence === 1 ? 'stored' : 'converted',
                    'exchange_point_snapshot' => $prize['exchange_points'],
                    'exchanged_point_amount' => $sequence === 1 ? null : $prize['exchange_points'],
                    'acquired_at' => $usedAt,
                    'storage_expires_at' => date('c', strtotime($usedAt.' +60 days')),
                    'terminal_at' => $sequence === 1 ? null : $usedAt,
                    'created_at' => $usedAt,
                    'updated_at' => $usedAt,
                ]);
            }
        }

        return $requestId;
    }

    private function processingRequest(): void
    {
        $this->request('processing', false, '2026-08-04T03:00:00Z');
    }

    private function qaRequest(): void
    {
        $adminId = DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => 'qa-history@example.test',
            'email_normalized' => 'qa-history@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => 'owner',
            'state' => 'active',
        ]);
        $modeId = DB::table('qa_test_user_modes')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $this->fixture['user_id'],
            'is_enabled' => true,
            'reason' => 'History exclusion fixture',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'enabled_by_admin_id' => $adminId,
            'disabled_at' => null,
            'disabled_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planId = DB::table('qa_draw_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => 'QA-HISTORY-'.strtoupper(Str::random(8)),
            'user_id' => $this->fixture['user_id'],
            'gacha_id' => $this->fixture['gacha_id'],
            'status' => 'active',
            'title' => 'History exclusion fixture',
            'reason' => 'History exclusion fixture',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'created_by_admin_id' => $adminId,
            'updated_by_admin_id' => $adminId,
            'revision' => 1,
            'archived_at' => null,
            'archived_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->request(
            'completed',
            true,
            '2026-08-04T04:00:00Z',
            $modeId,
            $planId
        );
    }

    private function request(
        string $status,
        bool $qa,
        string $time,
        ?int $modeId = null,
        ?int $planId = null
    ): string {
        $publicId = (string) Str::uuid7();
        $idempotencyId = DB::table('idempotency_records')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'scope' => 'draw',
            'actor_type' => 'user',
            'actor_public_id' => $this->fixture['user_public_id'],
            'key_hash' => hash('sha256', $publicId),
            'request_hash' => hash('sha256', 'request-'.$publicId),
            'status' => 'completed',
            'resource_type' => 'draw_request',
            'resource_public_id' => $publicId,
            'response_status' => 200,
            'response_data' => '{}',
            'created_at' => $time,
            'completed_at' => $time,
            'expires_at' => date('c', strtotime($time.' +24 hours')),
        ]);
        DB::table('draw_requests')->insert([
            'public_id' => $publicId,
            'user_id' => $this->fixture['user_id'],
            'gacha_draw_state_id' => $this->fixture['draw_state_id'],
            'gacha_version_id' => $this->fixture['version_id'],
            'probability_version_id' => $this->fixture['probability_id'],
            'idempotency_record_id' => $idempotencyId,
            'request_id' => (string) Str::uuid7(),
            'request_hash' => hash('sha256', 'request-'.$publicId),
            'catalog_snapshot_sha256' => str_repeat('1', 64),
            'requested_count' => 5,
            'executed_count' => $status === 'completed' ? 5 : 0,
            'point_cost_total' => 500,
            'consumed_paid_points' => $status === 'completed' ? 500 : 0,
            'consumed_free_points' => 0,
            'wallet_paid_after' => $status === 'completed' ? 500 : null,
            'wallet_free_after' => $status === 'completed' ? 0 : null,
            'point_back_total' => $status === 'completed' ? 300 : 0,
            'status' => $status,
            'processing_duration_ms' => $status === 'completed' ? 12 : null,
            'response_data' => $status === 'completed' ? '{}' : null,
            'created_at' => $time,
            'completed_at' => $status === 'completed' ? $time : null,
            'is_qa_draw' => $qa,
            'qa_test_user_mode_id' => $modeId,
            'qa_draw_plan_id' => $planId,
        ]);

        return $publicId;
    }

    /** @return array<string, mixed> */
    private function drawFixture(): array
    {
        $userPublicId = (string) Str::uuid7();
        $userId = DB::table('users')->insertGetId([
            'public_id' => $userPublicId,
            'display_name' => '利用ユーザー',
            'email_display' => 'usage-history@example.test',
            'email_normalized' => 'usage-history@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gachaId = (int) DB::table('catalog_gachas')->where('public_id', self::GACHA_ID)->value('id');
        $versionId = (int) DB::table('catalog_gacha_versions')->where('gacha_id', $gachaId)->value('id');
        $probabilityId = (int) DB::table('catalog_probability_versions')
            ->where('gacha_version_id', $versionId)->value('id');
        $stageId = (int) DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probabilityId)->orderBy('id')->value('id');
        $drawStateId = (int) DB::table('gacha_draw_states')
            ->where('gacha_version_id', $versionId)
            ->value('id');
        $prizes = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_gacha_ranks as gacha_rank', 'gacha_rank.id', '=', 'prize.gacha_rank_id')
            ->join('catalog_rank_masters as rank_master', 'rank_master.id', '=', 'gacha_rank.rank_master_id')
            ->join(
                'catalog_rank_master_revisions as rank_revision',
                'rank_revision.id',
                '=',
                'rank_master.current_revision_id'
            )
            ->leftJoin('catalog_ranks as legacy_rank', 'legacy_rank.public_id', '=', 'rank_master.public_id')
            ->leftJoin('catalog_presentation_assets as asset', 'asset.id', '=', 'prize.presentation_asset_id')
            ->where('relation.gacha_version_id', $versionId)
            ->orderBy('relation.sort_order')
            ->limit(2)
            ->get([
                'relation.id as relation_id',
                'prize.public_id as prize_public_id',
                'prize.display_name as prize_name',
                'prize.exchange_points',
                'legacy_rank.id as rank_id',
                'rank_master.public_id as rank_public_id',
                'legacy_rank.code as rank_code',
                'rank_revision.rank_name',
                'asset.public_id as asset_public_id',
                'asset.public_path',
                'asset.media_type',
                'asset.mime_type',
                'asset.alt_text',
                'asset.is_public',
            ])->map(fn (object $row): array => [
                'relation_id' => (int) $row->relation_id,
                'rank_id' => (int) $row->rank_id,
                'exchange_points' => (int) $row->exchange_points,
                'rank' => [
                    'id' => $row->rank_public_id,
                    'code' => $row->rank_code,
                    'name' => $row->rank_name,
                ],
                'prize' => [
                    'id' => $row->prize_public_id,
                    'name' => $row->prize_name,
                    'presentation_asset' => $row->asset_public_id === null ? null : [
                        'id' => $row->asset_public_id,
                        'public_path' => $row->public_path,
                        'media_type' => $row->media_type,
                        'mime_type' => $row->mime_type,
                        'alt_text' => $row->alt_text,
                        'is_public' => (bool) $row->is_public,
                    ],
                ],
            ])->all();

        return [
            'user_id' => $userId,
            'user_public_id' => $userPublicId,
            'gacha_id' => $gachaId,
            'version_id' => $versionId,
            'probability_id' => $probabilityId,
            'stage_id' => $stageId,
            'draw_state_id' => $drawStateId,
            'prizes' => $prizes,
        ];
    }

    private function sessionToken(V2AdminRole $role): string
    {
        $email = 'usage-history-'.$role->value.'-'.Str::uuid7().'@example.test';
        $adminId = DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $createdAt = now()->subSecond();
        $lastActivityAt = now();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $createdAt,
            'last_activity_at' => $lastActivityAt,
            'idle_expires_at' => $lastActivityAt->copy()->addMinutes(15),
            'absolute_expires_at' => $createdAt->copy()->addHours(8),
        ]);

        return $token;
    }

    private function adminContext(): V2AdminAuthorizationContext
    {
        $publicId = (string) Str::uuid7();
        $adminId = DB::table('admins')->insertGetId([
            'public_id' => $publicId,
            'email_display' => 'usage-history-query@example.test',
            'email_normalized' => 'usage-history-query@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner->value,
            'state' => 'active',
        ]);
        $sessionHash = app(V2SessionPolicy::class)->hashSessionId(
            app(V2SessionPolicy::class)->issueOpaqueSessionId()
        );
        $createdAt = now()->subSecond();
        $lastActivityAt = now();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash,
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $createdAt,
            'last_activity_at' => $lastActivityAt,
            'idle_expires_at' => $lastActivityAt->copy()->addMinutes(15),
            'absolute_expires_at' => $createdAt->copy()->addHours(8),
        ]);

        return new V2AdminAuthorizationContext(
            (int) $adminId,
            $publicId,
            V2AdminRole::Owner,
            $sessionHash,
            hash('sha256', $sessionHash),
            (string) Str::uuid7()
        );
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }
}

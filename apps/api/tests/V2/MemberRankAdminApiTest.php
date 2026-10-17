<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankAdminApiTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_operator_reads_all_surfaces_but_cannot_preview_or_write(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $token = $this->adminSession(V2AdminRole::Operator);
        foreach (['/member-ranks/summary', '/member-ranks/manual-changes', '/users', '/users/'.$user->public_id,
            '/users/'.$user->public_id.'/member-rank-history?history=rank', '/users/'.$user->public_id.'/member-rank-history?history=points'] as $path) {
            $this->asAdmin($token)->getJson('/admin/api/v2'.$path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        $base = '/admin/api/v2/users/'.$user->public_id;
        $this->asAdmin($token)->getJson($base.'/member-rank/preview?'.http_build_query($this->input()))->assertForbidden();
        $this->csrf($token)->putJson($base.'/member-rank', [])->assertForbidden();
        $this->csrf($token)->postJson($base.'/member-rank-point-adjustments', [])->assertForbidden();
        self::assertSame(0, DB::table('member_rank_manual_changes')->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
    }

    public function test_real_routes_preview_save_replay_history_and_request_ids(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            $token = $this->adminSession($role);
            $base = '/admin/api/v2/users/'.$user->public_id;
            $input = $this->input();
            $preview = $this->asAdmin($token)->getJson($base.'/member-rank/preview?'.http_build_query($input))->assertOk()->json('data');
            unset($input['operation']);
            $input['grant_bonus'] = true;
            $input['skip_count'] = 1;
            $payload = [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']];
            $key = (string) Str::uuid7();
            $this->csrf($token, $key)->putJson($base.'/member-rank', $payload)->assertOk()->assertJsonPath('data.after_rank', 'gold');
            $this->csrf($token, $key)->putJson($base.'/member-rank', $payload)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
            $this->asAdmin($token)->getJson($base.'/member-rank-history?history=rank')->assertOk()->assertJsonPath('items.0.reason', 'manual');
        }
        self::assertSame(3, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
    }

    public function test_no_session_wrong_realm_mfa_csrf_and_origin_are_rejected(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $base = '/admin/api/v2/users/'.$user->public_id;
        $this->getJson('/admin/api/v2/member-ranks/summary')->assertUnauthorized();
        $token = $this->adminSession(V2AdminRole::Admin);
        $this->asAdmin($token)->putJson($base.'/member-rank', [])->assertForbidden();
        $this->csrf($token)->withHeader('Origin', 'https://untrusted.example.test')->postJson($base.'/member-rank-point-adjustments', [])->assertForbidden();
        $hash = app(V2SessionPolicy::class)->hashSessionId($token);
        DB::table('admin_sessions')->where('session_id_hash', $hash)->update(['mfa_verified_at' => null]);
        $this->asAdmin($token)->getJson('/admin/api/v2/member-ranks/summary')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withUnencryptedCookie('__Host-oripa_admin_session', '')->withUnencryptedCookie('__Host-oripa_user_session', $token)
            ->getJson('/admin/api/v2/member-ranks/summary')->assertUnauthorized();
    }

    public function test_invalid_filters_missing_users_and_disabled_reads_are_safe(): void
    {
        $token = $this->adminSession(V2AdminRole::Admin);
        $user = $this->rankUser();
        $this->asAdmin($token)->getJson('/admin/api/v2/users?member_rank=beginner')->assertOk()->assertJsonPath('items.0.member_rank', 'beginner');
        $this->asAdmin($token)->getJson('/admin/api/v2/users/'.$user->public_id)->assertOk()->assertJsonPath('data.member_rank_detail.enabled', false);
        $this->asAdmin($token)->getJson('/admin/api/v2/member-ranks/summary')->assertOk()->assertJsonPath('data.active_total', 1);
        $this->asAdmin($token)->getJson('/admin/api/v2/users?member_rank[]=gold')->assertUnprocessable();
        $this->asAdmin($token)->getJson('/admin/api/v2/users?transferred=invalid')->assertUnprocessable();
        $this->asAdmin($token)->getJson('/admin/api/v2/member-ranks/manual-changes?kind=invalid')->assertUnprocessable();
        $this->asAdmin($token)->getJson('/admin/api/v2/member-ranks/manual-changes?date_from=2026-02-30')->assertUnprocessable();
        $this->asAdmin($token)->getJson('/admin/api/v2/users/'.Str::uuid7().'/member-rank-history?history=rank')->assertNotFound();
        $this->asAdmin($token)->getJson('/admin/api/v2/users/not-a-user/member-rank-history?history=rank')->assertNotFound();
    }

    private function input(): array
    {
        return ['operation' => 'rank', 'rank' => 'gold', 'kind' => 'support', 'reason' => 'API検証', 'skip_count' => '1', 'grant_bonus' => 'true'];
    }

    private function adminSession(V2AdminRole $role): string
    {
        $context = $this->rankAdmin();
        DB::table('admins')->where('id', $context->adminId)->update(['role' => $role->value]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        DB::table('admin_sessions')->where('session_id_hash', $context->sessionIdHash)
            ->update(['session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token)]);

        return $token;
    }

    private function asAdmin(string $token): static
    {
        Auth::forgetGuards();

        return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function csrf(string $token, ?string $key = null): static
    {
        $csrf = str_repeat('c', 64);

        return $this->asAdmin($token)->withServerVariables(['HTTPS' => 'on'])->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => $key ?? (string) Str::uuid7()]);
    }
}

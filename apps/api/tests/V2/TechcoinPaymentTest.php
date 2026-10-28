<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Domain\Payment\V2\Services\V2PaymentMethodPolicy;
use App\Domain\Payment\V2\Services\V2TechcoinConfiguration;
use App\Domain\Payment\V2\Services\V2TechcoinIdentity;
use App\Domain\Payment\V2\Services\V2TechcoinKickbackService;
use App\Domain\Payment\V2\Services\V2TechcoinPaymentService;
use App\Http\Controllers\V2\V2PaymentController;
use App\Http\Controllers\V2\V2TechcoinReturnController;
use App\Http\Controllers\V2\V2TechcoinWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\V2\Fixtures\TechcoinScenario;
use Tests\TestCase;

final class TechcoinPaymentTest extends TestCase
{
    use TechcoinScenario;
    use \Tests\Support\MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp(); $this->configureTechcoin();
        DB::beginTransaction(); Http::preventStrayRequests(); Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        while (DB::transactionLevel() > 0) DB::rollBack();
        \Carbon\Carbon::setTestNow(); \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_form_fields_identity_expiry_and_idempotency(): void
    {
        [$user, $payment, $input, $result] = $this->techcoinPayment(key: 'synthetic-idempotency');
        self::assertSame('techcoin', $payment->provider_code);
        self::assertSame('requires_action', $result['status']);
        self::assertSame(86400, strtotime($payment->expires_at) - strtotime($payment->created_at));
        self::assertSame('form_post', $result['next_action']['type']);
        self::assertSame('https://secure.tc-site.com/access', $result['next_action']['url']);
        self::assertSame(['clientip', 'payment_code', 'cookie', 'price', 'sendid', 'email'], array_column($result['next_action']['fields'], 'name'));
        self::assertSame(['0123456789', '10055', $input['cookie'], '3000', $input['sendid'], $user->email_normalized], array_column($result['next_action']['fields'], 'value'));
        $plan = DB::table('point_purchase_plans')->where('id', $payment->point_purchase_plan_id)->first();
        self::assertSame($result, app(V2TechcoinPaymentService::class)->start($user, $plan->public_id, 'synthetic-idempotency'));
        [, , $second] = $this->techcoinPayment($user, $plan);
        self::assertSame($input['sendid'], $second['sendid']); self::assertNotSame($input['cookie'], $second['cookie']);
        [$other, , $third] = $this->techcoinPayment(); self::assertNotSame($input['sendid'], $third['sendid']);
        self::assertSame(26, strlen($third['sendid']));
        $this->problem(fn () => app(V2TechcoinPaymentService::class)->start($user, $this->techcoinPlan(4000)->public_id, 'synthetic-idempotency'), 'IDEMPOTENCY_KEY_REUSED', 409);
        $this->problem(fn () => app(V2FincodePaymentService::class)->resume($user, $payment->public_id), 'UNPAID_PAYMENT_NOT_RESUMABLE', 409);
    }

    public function test_sendid_encodes_all_uuid_bits_without_precision_loss(): void
    {
        self::assertSame(str_repeat('0', 26), V2TechcoinIdentity::sendid('00000000-0000-0000-0000-000000000000'));
        self::assertSame(str_repeat('v', 25).'s', V2TechcoinIdentity::sendid('ffffffff-ffff-ffff-ffff-ffffffffffff'));
        $seen = [];
        for ($bit = 0; $bit < 128; $bit++) {
            $bytes = str_repeat("\0", 16); $bytes[intdiv($bit, 8)] = chr(1 << ($bit % 8));
            $hex = bin2hex($bytes); $uuid = V2TechcoinIdentity::paymentId($hex);
            $encoded = V2TechcoinIdentity::sendid($uuid);
            self::assertMatchesRegularExpression('/\A[a-z0-9]{26}\z/', $encoded);
            self::assertArrayNotHasKey($encoded, $seen); $seen[$encoded] = true;
        }
    }

    public static function badConfiguration(): array
    {
        return [['client_ip', '123'], ['client_ip', 1234567890], ['endpoint', 'https://evil.example.test/access'],
            ['endpoint', 'http://secure.tc-site.com/access'], ['payment_code', '10056'], ['kickback_path_token', 'short'],
            ['kickback_allowed_ips', '192.0.2.0/33'], ['kickback_allowed_ips', 'bad'], ['mode', ''], ['mode', 'production']];
    }

    #[DataProvider('badConfiguration')]
    public function test_bad_configuration_disables_new_purchase(string $key, mixed $value): void
    {
        config(['v2_techcoin.'.$key => $value]);
        self::assertFalse(app(V2TechcoinConfiguration::class)->available());
        self::assertFalse(app(V2PaymentMethodPolicy::class)->methods(null)['data'][0]['available']);
        $this->problem(fn () => app(V2TechcoinPaymentService::class)->start($this->techcoinUser(), $this->techcoinPlan()->public_id, (string) Str::uuid()), 'PAYMENT_PROVIDER_UNAVAILABLE', 503);
        self::assertSame(0, DB::table('payments')->where('provider_code', 'techcoin')->count());
    }

    public function test_server_amount_boundary_order_and_card_rejection(): void
    {
        config(['v2_payment.methods_enabled' => 'paidy,netridecash,credit_card']);
        self::assertSame(['paidy', 'netridecash', 'credit_card'], array_column(app(V2PaymentMethodPolicy::class)->methods(null)['data'], 'method'));
        $small = $this->techcoinPlan(999999); $large = $this->techcoinPlan(1000000);
        self::assertTrue(app(V2PaymentMethodPolicy::class)->methods($small->public_id)['data'][1]['available']);
        self::assertSame('999999', $this->techcoinPayment(plan: $small)[3]['next_action']['fields'][3]['value']);
        self::assertSame('amount_too_large', app(V2PaymentMethodPolicy::class)->methods($large->public_id)['data'][1]['unavailable_reason']);
        $this->problem(fn () => app(V2TechcoinPaymentService::class)->start($this->techcoinUser(), $large->public_id, (string) Str::uuid()), 'PAYMENT_AMOUNT_TOO_LARGE', 422);
        $this->problem(fn () => app(V2PaymentMethodPolicy::class)->assertNewPayment('netridecash', $small->public_id, null, true), 'PAYMENT_REQUEST_INVALID', 422);
    }

    public function test_success_and_retries_after_hidden_method_grant_once_and_keep_no_raw_notification(): void
    {
        [$user, $payment, $input] = $this->techcoinPayment(); $input['order_cpt'] = 'synthetic-order';
        config(['v2_payment.methods_enabled' => 'paidy', 'v2_techcoin.mode' => 'live']);
        for ($i = 0; $i < 4; $i++) self::assertSame(200, $this->notify($input)->getStatusCode());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded', 'provider_payment_id' => 'synthetic-order']);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(3000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        self::assertSame(100, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertSame(1, DB::table('payment_receipt_snapshots')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->count());
        self::assertSame('test', DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->value('classification'));
        $event = DB::table('payment_provider_events')->where('payment_id', $payment->id)->sole();
        self::assertNull($event->provider_occurred_at); self::assertNotNull($event->received_at);
        self::assertStringNotContainsString($input['sendid'], Crypt::decryptString($event->payload_ciphertext));
        self::assertSame('{}', $event->headers_redacted);
        self::assertCount(1, app(V2FincodePaymentService::class)->history($user, 'succeeded', null, 20)['data']);
        $input['rel'] = '0'; self::assertSame(200, $this->notify($input)->getStatusCode());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
        $input['rel'] = '1'; $input['order_cpt'] = 'different-order'; $this->notify($input);
        $this->assertReview($payment, 'duplicate_charge');
    }

    public function test_account_closure_late_techcoin_success_is_reviewed_once_without_points(): void
    {
        [$user, $payment, $input] = $this->techcoinPayment();
        app(\App\Domain\Identity\Services\V2UserClosureService::class)->closeLocked($user, 'admin', 'normal', (string) Str::uuid7());
        foreach ([1, 2] as $attempt) self::assertSame(200, $this->notify($input)->getStatusCode());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('account_closure_late_payments')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('event_key', 'account.closure.payment:'.$payment->public_id)->count());
    }

    public static function badNotification(): array
    {
        $cases = [];
        foreach (['100', '03000', '3000.0', '3e3', '+3000', '-3000', '3000 ', ' 3000', '３０００', '', '999999999999999999999', 3000.0, true, ['3000']] as $value) $cases[] = ['price', $value, 'amount_mismatch'];
        foreach ([['sendid', 'wrong', 'member_mismatch'], ['sendid', [], 'member_mismatch'], ['payment_code', '10056', 'source_mismatch'],
            ['rel', '2', 'source_mismatch'], ['rel', true, 'source_mismatch'], ['order_cpt', '', 'source_mismatch'],
            ['order_cpt', 'private@example.test', 'source_mismatch'], ['currency', 'USD', 'source_mismatch'], ['sendpoint', [], 'source_mismatch']] as $case) $cases[] = $case;
        return $cases;
    }

    #[DataProvider('badNotification')]
    public function test_mismatch_returns_200_without_grant_and_records_bounded_review(string $key, mixed $value, string $reason): void
    {
        [, $payment, $input] = $this->techcoinPayment(); $input[$key] = $value;
        self::assertSame(200, $this->notify($input, json: true)->getStatusCode());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'requires_action']);
        $this->assertReview($payment, $reason);
    }

    public function test_token_ip_cidr_unknown_cookie_and_reference_only_sendpoint(): void
    {
        [, $payment, $input] = $this->techcoinPayment();
        self::assertSame(404, $this->notify($input, token: 'invalid')->getStatusCode());
        config(['v2_techcoin.kickback_allowed_ips' => '192.0.2.0/24,2001:db8::/32']);
        self::assertSame(403, $this->notify($input, ip: '198.51.100.1')->getStatusCode());
        self::assertTrue(app(V2TechcoinConfiguration::class)->acceptsIp('2001:db8::4'));
        self::assertFalse(app(V2TechcoinConfiguration::class)->acceptsIp('2001:db9::4'));
        $wrong = $input; $wrong['cookie'] = str_repeat('a', 32);
        self::assertSame(200, $this->notify($wrong, ip: '192.0.2.8')->getStatusCode());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        $input['sendpoint'] = 'https://evil.example.test/';
        self::assertSame(200, $this->notify($input, ip: '192.0.2.8')->getStatusCode());
        self::assertSame($input['cookie'], DB::table('payments')->where('id', $payment->id)->value('provider_payment_id'));
    }

    public static function terminalStates(): array { return [['failed'], ['canceled'], ['expired'], ['deadline']]; }

    #[DataProvider('terminalStates')]
    public function test_terminal_or_overdue_success_never_grants(string $state): void
    {
        [, $payment, $input] = $this->techcoinPayment();
        DB::table('payments')->where('id', $payment->id)->update($state === 'deadline'
            ? ['expires_at' => now()->subSecond()] : ['status' => $state]);
        self::assertSame(200, $this->notify($input)->getStatusCode());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        $this->assertReview($payment, 'expired');
    }

    public function test_failure_must_match_and_does_not_authorize_later_success(): void
    {
        [, $payment, $input] = $this->techcoinPayment(); $input['rel'] = '0';
        $bad = $input; $bad['price'] = '100'; $this->notify($bad);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'requires_action']);
        $this->notify($input); $this->notify($input);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed']);
        $input['rel'] = '1'; $this->notify($input);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        $this->assertReview($payment, 'expired');
    }

    public function test_order_cpt_cannot_be_reused_between_payments(): void
    {
        [, $first, $a] = $this->techcoinPayment(); [, $second, $b] = $this->techcoinPayment();
        $a['order_cpt'] = $b['order_cpt'] = 'same-provider-order';
        $this->notify($a); $this->notify($b);
        self::assertSame(1, DB::table('payment_point_grants')->whereIn('payment_id', [$first->id, $second->id])->count());
        $this->assertReview($second, 'duplicate_charge');
    }

    public function test_return_get_only_redirects_and_never_changes_financial_or_event_state(): void
    {
        [, $payment, $input, $result] = $this->techcoinPayment();
        $before = $this->counts();
        $controller = app(V2TechcoinReturnController::class);
        foreach (['complete', 'failure'] as $kind) {
            $response = $controller->$kind(Request::create('/synthetic', 'GET', ['ref' => $input['cookie'], 'url' => 'https://evil.example.test']));
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('https://storefront.example.test/points/purchase/'.($kind === 'complete' ? 'thanks' : $result['point_product_id']).'?pid='.$payment->public_id, $response->headers->get('Location'));
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        }
        foreach ([null, 'bad', str_repeat('f', 32), ['bad'], $input['cookie'].' '] as $ref) {
            $response = $controller->complete(Request::create('/synthetic', 'GET', ['ref' => $ref]));
            self::assertSame('https://storefront.example.test/points', $response->headers->get('Location'));
        }
        self::assertSame($before, $this->counts());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'requires_action']);
    }

    public function test_raw_form_does_not_trim_price_and_rejects_duplicate_fields(): void
    {
        [, $payment, $input] = $this->techcoinPayment(); $input['price'] = '3000 ';
        self::assertSame(200, $this->notify($input)->getStatusCode()); $this->assertReview($payment, 'amount_mismatch');
        $raw = http_build_query($input).'&price=3000';
        $request = Request::create('/synthetic', 'POST', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: $raw);
        self::assertSame(422, app(V2TechcoinWebhookController::class)($request, config('v2_techcoin.kickback_path_token'))->getStatusCode());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }

    public function test_db_failure_rolls_back_status_reference_event_wallet_receipt_and_mail_and_returns_500(): void
    {
        [, $payment, $input] = $this->techcoinPayment();
        DB::unprepared("CREATE FUNCTION synthetic_techcoin_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic grant failure'; END; $$; CREATE TRIGGER synthetic_techcoin_fail BEFORE INSERT ON payment_point_grants FOR EACH ROW EXECUTE FUNCTION synthetic_techcoin_fail();");
        self::assertSame(500, $this->notify($input)->getStatusCode());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'requires_action', 'provider_payment_id' => null]);
        foreach (['payment_point_grants', 'payment_receipt_snapshots'] as $table) self::assertSame(0, DB::table($table)->where('payment_id', $payment->id)->count());
        self::assertSame(0, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
        self::assertSame(0, DB::table('payment_provider_events')->where('payment_id', $payment->id)->where('event_type', 'payment.succeeded')->count());
        $this->assertReview($payment, 'source_mismatch');
        DB::unprepared('DROP TRIGGER synthetic_techcoin_fail ON payment_point_grants; DROP FUNCTION synthetic_techcoin_fail();');
        self::assertSame(200, $this->notify($input)->getStatusCode());
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }

    public function test_live_settlement_reuses_limited_rank_mail_and_two_phase_manual_refund(): void
    {
        $this->configureRankFixture(); $this->enableRankFixture(); config(['v2_techcoin.mode' => 'live']);
        $user = $this->techcoinUser(); $this->seedRankState($user, 2); $plan = $this->techcoinPlan(4000, 100);
        DB::table('point_purchase_plan_limited_bonus_campaigns')->insert([
            'public_id' => (string) Str::uuid7(), 'point_purchase_plan_id' => $plan->id,
            'is_enabled' => true, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'bonus_point_amount' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        [, $payment, $input] = $this->techcoinPayment($user, $plan); $this->notify($input); $this->notify($input);
        self::assertSame(4000, (int) DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->sum('applied_delta'));
        self::assertSame(20, (int) DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->value('bonus_amount'));
        self::assertSame(170, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertTrue((bool) DB::selectOne('SELECT v2_payment_commercial(?) AS value', [$payment->id])->value);
        $service = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class); $admin = $this->rankAdmin();
        $prepared = $service->write($admin, $payment->public_id, 'refund', ['reason' => 'Synthetic refund preparation'], (string) Str::uuid7());
        self::assertSame('points_reserved', $prepared['data']['status']);
        $service->write($admin, $payment->public_id, 'complete', ['reason' => 'Synthetic refund confirmed', 'provider_refund_confirmed' => true],
            (string) Str::uuid7(), $prepared['data']['id']);
        self::assertSame(0, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        self::assertSame(0, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
    }

    public function test_admin_review_labels_reference_and_immutable_reason_survive_progress_purge(): void
    {
        $present = \Carbon\CarbonImmutable::now();
        $this->rankClock($present->subDays(367)->toIso8601String());
        [, $payment, $input] = $this->techcoinPayment(); $input['price'] = '100'; $this->notify($input);
        $this->rankClock($present->toIso8601String());
        $admin = $this->rankAdmin(); $read = app(\App\Domain\Payment\V2\Services\V2AdminPaymentReadService::class);
        $list = $read->all($admin, null, 20, null, null, null, 'techcoin', 'review_required');
        self::assertSame($payment->public_id, $list['data'][0]['id']);
        self::assertTrue($list['data'][0]['adjustments']['review_required']);
        self::assertSame('テックコイン', $list['data'][0]['provider_label']);
        self::assertSame('電子マネー（NetRideCash）', $list['data'][0]['method_label']);
        // App-created historical progress is expired under both the PHP and PostgreSQL clocks.
        app(\App\Domain\Payment\V2\Services\V2PaymentProgressService::class)->purge();
        self::assertSame(0, DB::table('payment_progress_events')->where('payment_id', $payment->id)->count());
        self::assertCount(1, $read->all($admin, null, 20, null, null, null, 'techcoin', 'review_required')['data']);
    }

    public function test_public_start_enforces_auth_csrf_sms_owner_and_server_fields(): void
    {
        $user = $this->techcoinUser(); $plan = $this->techcoinPlan();
        config(['v2_identity.origins.user' => 'https://example.test']);
        $body = ['point_product_id' => $plan->public_id, 'payment_method' => 'netridecash'];
        $this->withCredentials()->withHeader('Origin', 'https://example.test')->postJson('/api/v2/payments', $body)->assertStatus(403);
        $csrf = str_repeat('c', 64);
        $this->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)->withHeaders(['Origin' => 'https://example.test', 'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => (string) Str::uuid7()]);
        $this->postJson('/api/v2/payments', $body)->assertStatus(401);
        $this->actingAs($user, 'v2_user');
        $response = $this->postJson('/api/v2/payments', $body)->assertStatus(201)->assertJsonPath('next_action.type', 'form_post');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->postJson('/api/v2/payments', $body + ['price' => 100])->assertStatus(422);
        $other = $this->techcoinUser(); $this->actingAs($other, 'v2_user');
        $this->getJson('/api/v2/payments/'.$response->json('id'))->assertStatus(404);
        DB::table('user_phone_numbers')->where('user_id', $other->id)->update(['revoked_at' => now()]);
        $this->postJson('/api/v2/payments', $body)->assertStatus(403)->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
    }

    private function notify(array $input, bool $json = false, ?string $token = null, string $ip = '198.51.100.2')
    {
        $request = Request::create('/synthetic', 'POST', server: ['REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => $json ? 'application/json' : 'application/x-www-form-urlencoded'],
            content: $json ? json_encode($input, JSON_PRESERVE_ZERO_FRACTION) : http_build_query($input));
        return app(V2TechcoinWebhookController::class)($request, $token ?? config('v2_techcoin.kickback_path_token'));
    }

    private function assertReview(object $payment, string $reason): void
    {
        self::assertTrue(DB::table('payment_progress_events')->where('payment_id', $payment->id)->whereRaw("detail->>'match' = ?", [$reason])->exists());
        self::assertTrue(DB::table('payment_provider_events')->where('payment_id', $payment->id)->where('event_type', 'payment.review.'.$reason)->exists());
    }

    private function counts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['payment_provider_events', 'payment_point_grants', 'point_ledger_entries', 'member_rank_point_entries', 'mail_deliveries', 'payment_progress_events']);
    }

    private function problem(callable $call, string $code, int $status): void
    {
        try { $call(); self::fail('Expected rejection'); }
        catch (V2FincodeException $e) { self::assertSame($code, $e->errorCode); self::assertSame($status, $e->status); }
    }
}

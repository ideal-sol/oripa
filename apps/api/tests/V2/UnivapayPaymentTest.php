<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Domain\Payment\V2\Services\V2PaymentMethodPolicy;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Payment\V2\Services\V2UnivapayConfiguration;
use App\Domain\Payment\V2\Services\V2UnivapayPaymentService;
use App\Domain\Payment\V2\Services\V2UnivapayWebhookService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Http\Controllers\V2\V2PaymentController;
use App\Http\Controllers\V2\V2UnivapayWebhookController;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\V2\Fixtures\UnivapayFixtures;

final class UnivapayPaymentTest extends TestCase
{
    use DatabaseTransactions, UnivapayFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureUnivapay();
    }

    public function test_integrated_provider_discovery_start_and_pending_presentation_are_independent(): void
    {
        config([
            'v2_payment.methods_enabled' => 'credit_card,paidy',
            'v2_paidy.api_base_url' => 'https://api.paidy.com', 'v2_paidy.api_version' => '2018-04-10',
            'v2_paidy.public_key' => 'pk_synthetic_public_key', 'v2_paidy.secret_key' => 'sk_synthetic_server_key',
            'v2_paidy.store_name' => 'Synthetic store', 'v2_paidy.mode' => 'test',
            'v2_paidy.webhook_path_token' => str_repeat('p', 32), 'v2_paidy.worker_enabled' => false,
        ]);
        $user = $this->univapayUser(); $plan = $this->univapayPlan();
        $policy = app(V2PaymentMethodPolicy::class);
        self::assertSame([true, true], array_column($policy->methods($plan->public_id, $user)['data'], 'available'));
        $univapay = app(V2UnivapayPaymentService::class)->start($user, $plan->public_id, 'integrated-univapay');
        $paidy = app(\App\Domain\Payment\V2\Services\V2PaidyPaymentService::class)->start($user, $plan->public_id, 'integrated-paidy');
        self::assertNotSame($univapay['id'], $paidy['id']);
        self::assertSame('univapay_inline', $univapay['next_action']['type']);
        self::assertSame('paidy_checkout', $paidy['next_action']['type']);
        config(['v2_payment.methods_enabled' => '']);
        self::assertSame([], $policy->methods($plan->public_id, $user)['data']);
        foreach ([$univapay, $paidy] as $result) {
            $read = app(V2FincodePaymentService::class)->show($user, $result['id']);
            self::assertSame($result['next_action']['type'], $read['next_action']['type']);
            self::assertSame('requires_action', $read['status']);
            $payment = DB::table('payments')->where('public_id', $result['id'])->firstOrFail();
            self::assertSame('test', DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->value('classification'));
        }
        Http::assertNothingSent();
    }

    public function test_inline_configuration_amount_boundary_snapshot_and_idempotency(): void
    {
        $user = $this->univapayUser(); $plan = $this->univapayPlan(100000);
        $policy = app(V2PaymentMethodPolicy::class);
        $method = $policy->methods($plan->public_id, $user)['data'][0];
        self::assertTrue($method['available']); self::assertFalse($method['saved_cards_enabled']);
        self::assertSame('inline_card_form', $method['flow']);
        $form = $method['inline_form'];
        self::assertSame(100000, $form['options']['amount']); self::assertSame('one_time', $form['options']['tokenType']);
        self::assertTrue($form['options']['capture']); self::assertTrue($form['options']['inline']);
        self::assertSame($user->email_display, $form['options']['email']);
        self::assertNull($policy->methods(null, $user)['data'][0]['inline_form']);
        $service = app(V2UnivapayPaymentService::class);
        $first = $service->start($user, $plan->public_id, 'same-key');
        self::assertSame($first, $service->start($user, $plan->public_id, 'same-key'));
        self::assertSame('univapay_inline', $first['next_action']['type']);
        self::assertSame($form['options']['amount'], $first['next_action']['amount']);
        self::assertSame($first['id'], $first['next_action']['update']['metadata']['oripa-payment-id']);
        self::assertSame($user->public_id, $first['next_action']['update']['metadata']['oripa-member']);
        self::assertStringStartsWith('0', $first['next_action']['update']['phoneNumber']['localNumber']);
        self::assertSame('https://example.test/points/purchase/thanks?pid='.$first['id'], $first['next_action']['success_url']);
        self::assertSame(3600.0, CarbonImmutable::parse($first['created_at'])->diffInSeconds(CarbonImmutable::parse($first['expires_at'])));
        $large = $this->univapayPlan(100001);
        self::assertSame([], $policy->methods($large->public_id, $user)['data']);
        $this->problem(fn () => $service->start($user, $large->public_id, 'too-large'), 'PAYMENT_AMOUNT_TOO_LARGE', 422);
        config(['v2_payment.credit_card_provider' => 'fincode']);
        self::assertCount(1, $policy->methods($large->public_id, $user)['data']);
        foreach (['api_secret','webhook_path_token','webhook_auth_token'] as $key) self::assertStringNotContainsString(config('v2_univapay.'.$key), json_encode([$form,$first]));
        Http::assertNothingSent();
    }

    public function test_invalid_configuration_card_presence_sms_and_owner_boundaries(): void
    {
        $user = $this->univapayUser(); $plan = $this->univapayPlan();
        $this->actingAs($user, 'v2_user');
        $controller = app(V2PaymentController::class);
        foreach ([null, [], ['token'=>'synthetic'], ['number'=>'synthetic']] as $card) {
            $request = Request::create('/synthetic', 'POST', ['point_product_id'=>$plan->public_id,'payment_method'=>'credit_card','card'=>$card]);
            $request->headers->set('Idempotency-Key', 'reject-card');
            self::assertSame(422, $controller->store($request)->status());
        }
        foreach (['store_id','form_app_token','api_app_token','api_secret','mode','webhook_path_token','webhook_auth_token'] as $key) {
            $before = config('v2_univapay.'.$key); config(['v2_univapay.'.$key => null]);
            self::assertFalse(app(V2UnivapayConfiguration::class)->available());
            $this->problem(fn () => app(V2PaymentMethodPolicy::class)->assertNewPayment('credit_card', $plan->public_id, null, false), 'PAYMENT_PROVIDER_UNAVAILABLE', 503);
            config(['v2_univapay.'.$key => $before]);
        }
        config(['v2_univapay.mode'=>'live']); self::assertFalse(app(V2UnivapayConfiguration::class)->available());
        $this->configureUnivapay();
        [$owner, $payment] = $this->univapayPayment();
        $this->problem(fn () => app(V2UnivapayPaymentService::class)->report($user, $payment->public_id, ['charge_id'=>(string) Str::uuid()]), 'PAYMENT_NOT_FOUND', 404);
        $this->problem(fn () => app(V2UnivapayPaymentService::class)->report($owner, $payment->public_id, ['charge_id'=>'bad']), 'PAYMENT_REQUEST_INVALID', 422);
        $this->problem(fn () => app(V2FincodePaymentService::class)->resume($owner, $payment->public_id), 'UNPAID_PAYMENT_NOT_RESUMABLE', 409);
        DB::table('user_phone_numbers')->where('user_id', $user->id)->update(['revoked_at'=>now()]);
        $this->expectException(\App\Domain\Identity\Exceptions\V2AuthenticationException::class);
        app(V2UnivapayPaymentService::class)->start($user, $plan->public_id, 'no-sms');
    }

    public function test_success_replays_share_one_grant_receipt_and_mail_and_survive_method_switch(): void
    {
        [$user, $payment] = $this->univapayPayment(); $charge = $this->charge($payment); $this->fakeCharge($charge);
        $service = app(V2UnivapayPaymentService::class);
        config(['v2_payment.methods_enabled'=>'paypay','v2_payment.credit_card_provider'=>'fincode']);
        foreach (range(1, 3) as $attempt) {
            self::assertSame('succeeded', $service->report($user, $payment->public_id, ['charge_id'=>$charge['id']])['status']);
            $service->reconcile($charge['id']);
        }
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        self::assertSame(1, DB::table('payment_receipt_snapshots')->where('payment_id',$payment->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id',$payment->public_id)->count());
        self::assertSame(1, DB::table('outbox_messages')->where('deduplication_key','payment.succeeded:'.$payment->public_id)->count());
        self::assertSame(1, count(app(V2FincodePaymentService::class)->history($user,'succeeded',null,20)['data']));
        self::assertFalse((bool) DB::selectOne('SELECT v2_payment_commercial(?) AS eligible', [$payment->id])->eligible);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'polling=true'));
        foreach (DB::table('payment_provider_events')->where('payment_id',$payment->id)->get() as $event) {
            self::assertSame('{}', $event->headers_redacted);
            self::assertStringNotContainsString($user->email_display, Crypt::decryptString($event->payload_ciphertext));
        }
    }

    public static function mismatches(): array
    {
        return array_map(fn ($value) => [$value], ['id','store_id','mode','payment','member','requested_amount','charged_amount','requested_currency','charged_currency','transaction_token_type']);
    }

    #[DataProvider('mismatches')]
    public function test_every_provider_mismatch_blocks_grants(string $field): void
    {
        [$user, $payment] = $this->univapayPayment(); $charge = $this->charge($payment); $id = $charge['id'];
        if ($field === 'payment') $charge['metadata']['oripa-payment-id'] = (string) Str::uuid();
        elseif ($field === 'member') $charge['metadata']['oripa-member'] = (string) Str::uuid();
        else $charge[$field] = str_ends_with($field, 'amount') ? 999 : 'mismatch';
        $this->fakeCharge($charge);
        app(V2UnivapayPaymentService::class)->report($user,$payment->public_id,['charge_id'=>$id]);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        self::assertSame(1, DB::table('payment_progress_events')->where('payment_id',$payment->id)->whereRaw("detail->>'reason' = 'reconciliation_failed'")->count());
    }

    public static function statuses(): array { return [['pending','requires_action'],['authorized','requires_action'],['awaiting','requires_action'],['failed','failed'],['error','failed'],['canceled','canceled']]; }

    #[DataProvider('statuses')]
    public function test_non_success_states_do_not_grant(string $provider, string $expected): void
    {
        [$user,$payment] = $this->univapayPayment(); $charge=$this->charge($payment,$provider); $this->fakeCharge($charge);
        $result=app(V2UnivapayPaymentService::class)->report($user,$payment->public_id,['charge_id'=>$charge['id']]);
        self::assertSame($expected,$result['status']);
        self::assertSame(0,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
    }

    public function test_late_success_terminal_success_and_second_charge_require_review(): void
    {
        foreach (['expired','failed','canceled','deadline'] as $terminal) {
            [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment);
            if ($terminal === 'deadline') {
                $this->travel(60)->minutes();
            } else {
                $event=app(V2PaymentService::class)->recordVerifiedProviderEvent('univapay','terminal:'.$payment->public_id,'payment.'.$terminal,'{}',[],(int)$payment->id);
                app(V2PaymentService::class)->applyVerifiedStatus((int)$event->id,$terminal);
            }
            $this->fakeCharge($charge); app(V2UnivapayPaymentService::class)->report($user,$payment->public_id,['charge_id'=>$charge['id']]);
            self::assertSame(0,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
            self::assertSame(1,DB::table('payment_progress_events')->where('payment_id',$payment->id)->whereRaw("detail->>'match' = 'expired'")->count());
            $this->travelBack();
        }
        [$user,$payment]=$this->univapayPayment();
        foreach (range(1,2) as $n) { $charge=$this->charge($payment); $this->fakeCharge($charge); app(V2UnivapayPaymentService::class)->report($user,$payment->public_id,['charge_id'=>$charge['id']]); }
        self::assertSame(1,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        self::assertSame(1,DB::table('payment_progress_events')->where('payment_id',$payment->id)->whereRaw("detail->>'match' = 'duplicate_charge'")->count());
        [$other,$otherPayment]=$this->univapayPayment();
        $bound=DB::table('payments')->where('id',$payment->id)->value('provider_payment_id');
        $forged=$this->charge($otherPayment,'successful',$bound); $this->fakeCharge($forged);
        app(V2UnivapayPaymentService::class)->report($other,$otherPayment->public_id,['charge_id'=>$bound]);
        self::assertSame(0,DB::table('payment_point_grants')->where('payment_id',$otherPayment->id)->count());
    }

    public function test_webhook_is_durable_fast_authenticated_redacted_and_retryable(): void
    {
        [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment); $event=(string)Str::uuid();
        Http::fake(function () { usleep(3100000); return Http::response([],503); });
        $body=['id'=>$event,'event'=>'charge_finished','data'=>[...$charge,'email'=>'private@example.test','card_number'=>'do-not-retain']];
        $start=microtime(true);
        self::assertSame(200,$this->webhook($body));
        self::assertLessThan(3.0,microtime(true)-$start);
        self::assertSame(200,$this->webhook($body)); Http::assertNothingSent();
        self::assertSame(1,DB::table('outbox_messages')->where('topic',V2UnivapayWebhookService::TOPIC)->count());
        $payload=DB::table('outbox_messages')->where('topic',V2UnivapayWebhookService::TOPIC)->value('payload');
        self::assertEqualsCanonicalizing(['event_id','charge_id'],array_keys(json_decode($payload,true)));
        foreach ([502,503,504] as $status) {
            $this->fakeCharge([], $status); app(V2UnivapayWebhookService::class)->work();
            self::assertSame('requires_action',DB::table('payments')->where('id',$payment->id)->value('status'));
            $this->travel(61)->seconds();
        }
        $this->fakeCharge($charge); app(V2UnivapayWebhookService::class)->work();
        self::assertSame('succeeded',DB::table('payments')->where('id',$payment->id)->value('status'));
        self::assertSame('delivered',DB::table('outbox_messages')->where('topic',V2UnivapayWebhookService::TOPIC)->value('status'));
        self::assertSame(404,$this->webhook($body,'bad'));
        self::assertSame(401,$this->webhook($body,null,'bad'));
        config(['v2_univapay.notify_allowed_ips'=>'192.0.2.10']); self::assertSame(401,$this->webhook($body));
        config(['v2_univapay.notify_allowed_ips'=>'']); self::assertSame(200,$this->webhook(['event'=>'unknown']));
        foreach (DB::table('payment_provider_events')->where('provider_code','univapay')->get() as $receipt) {
            self::assertStringNotContainsString('private@example.test',Crypt::decryptString($receipt->payload_ciphertext));
            self::assertStringNotContainsString('do-not-retain',Crypt::decryptString($receipt->payload_ciphertext));
        }
    }

    public function test_lease_crash_recovery_and_refund_notification_never_reverse_points(): void
    {
        [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment); $this->fakeCharge($charge);
        self::assertSame(200,$this->webhook(['id'=>(string)Str::uuid(),'event'=>'charge_finished','data'=>$charge]));
        app(V2OutboxService::class)->claim('crashed-worker',20,1,[V2UnivapayWebhookService::TOPIC]);
        $this->travel(2)->seconds(); app(V2UnivapayWebhookService::class)->work();
        self::assertSame(1,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        foreach (['refund_finished','cancel_finished'] as $event) {
            self::assertSame(200,$this->webhook(['id'=>(string)Str::uuid(),'event'=>$event,'data'=>['charge_id'=>$charge['id']]]));
            app(V2UnivapayWebhookService::class)->work();
        }
        self::assertSame(0,DB::table('payment_adjustments')->where('payment_id',$payment->id)->count());
        self::assertSame(2,DB::table('payment_progress_events')->where('payment_id',$payment->id)->whereRaw("detail->>'reason' = 'provider_reversed'")->count());
        $charge['status']='canceled'; $this->fakeCharge($charge); app(V2UnivapayPaymentService::class)->reconcile($charge['id']);
        self::assertSame('succeeded',DB::table('payments')->where('id',$payment->id)->value('status'));
    }

    public function test_discovery_get_recovery_deadline_and_twenty_four_hour_search(): void
    {
        [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment);
        $this->travel(5)->minutes();
        Http::fake(fn ($request) => Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH),'/charges') ? ['items'=>[$charge],'has_more'=>false] : $charge));
        self::assertSame(1,app(V2UnivapayPaymentService::class)->reconcileDue());
        self::assertSame('succeeded',DB::table('payments')->where('id',$payment->id)->value('status'));
        Http::assertSentCount(2);
        [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment);
        $this->travel(60)->minutes(); $this->fakeCharge(['items'=>[],'has_more'=>false]);
        app(V2UnivapayPaymentService::class)->reconcileDue();
        self::assertSame('expired',DB::table('payments')->where('id',$payment->id)->value('status'));
        $this->travel(23)->hours();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH),'/charges') ? ['items'=>[$charge],'has_more'=>false] : $charge));
        self::assertSame(1,app(V2UnivapayPaymentService::class)->reconcileDue());
        self::assertSame(0,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        $this->travel(2)->hours(); self::assertSame(0,app(V2UnivapayPaymentService::class)->reconcileDue());
    }

    public function test_live_mode_remains_commercial_and_worker_is_disabled_by_default(): void
    {
        $this->configureUnivapay('live'); [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment); $this->fakeCharge($charge);
        app(V2UnivapayPaymentService::class)->report($user,$payment->public_id,['charge_id'=>$charge['id']]);
        self::assertTrue((bool)DB::selectOne('SELECT v2_payment_commercial(?) AS eligible',[$payment->id])->eligible);
        $this->artisan('v2:univapay:work')->assertExitCode(1);
        $this->artisan('v2:univapay:reconcile')->assertExitCode(1);
    }

    public function test_report_timeout_returns_current_payment_and_http_boundaries_remain_protected(): void
    {
        [$user,$payment]=$this->univapayPayment(); $this->actingAs($user,'v2_user');
        $id=(string)Str::uuid();
        Http::fake(['https://api.univapay.com/*'=>Http::failedConnection()]);
        $request=Request::create('/synthetic','POST',server:['CONTENT_TYPE'=>'application/json'],content:json_encode(['charge_id'=>$id]));
        $response=app(V2PaymentController::class)->univapayCharge($request,$payment->public_id);
        self::assertSame(200,$response->status()); self::assertSame('requires_action',$response->getData(true)['status']);
        self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->postJson('/api/v2/payments/'.$payment->public_id.'/univapay-charges',['charge_id'=>$id],['Origin'=>'https://example.test'])
            ->assertStatus(403)->assertJsonPath('code','CSRF_TOKEN_MISMATCH');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        self::assertSame(401,app(V2PaymentController::class)->univapayCharge($request,$payment->public_id)->status());
        $other=app(V2PaymentService::class)->createPayment((int)$user->id,(int)$payment->point_purchase_plan_id,'fincode','synthetic-other','other-provider','credit_card');
        $this->problem(fn () => app(V2UnivapayPaymentService::class)->report($user,$other->public_id,['charge_id'=>$id]),'PAYMENT_PROVIDER_MISMATCH',409);
    }

    public function test_webhook_payload_is_never_settlement_authority_and_durable_failure_is_retryable(): void
    {
        [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment,'authorized'); $this->fakeCharge($charge);
        self::assertSame(200,$this->webhook(['id'=>(string)Str::uuid(),'event'=>'charge_finished','data'=>[...$charge,'status'=>'successful','requested_amount'=>1]]));
        app(V2UnivapayWebhookService::class)->work();
        self::assertSame(0,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        $before=DB::table('payment_provider_events')->count();
        DB::unprepared("CREATE FUNCTION pay2_test_reject_enqueue() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic persistence failure''; END'; CREATE TRIGGER pay2_test_reject_enqueue BEFORE INSERT ON outbox_messages FOR EACH ROW EXECUTE FUNCTION pay2_test_reject_enqueue()");
        self::assertSame(503,$this->webhook(['id'=>(string)Str::uuid(),'event'=>'charge_finished','data'=>$charge]));
        self::assertSame($before,DB::table('payment_provider_events')->count());
    }

    private function webhook(array $body, ?string $path=null, ?string $auth=null): int
    {
        $request=Request::create('/synthetic','POST',server:['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>$auth ?? ' Bearer '.str_repeat('a',32).' '],content:json_encode($body));
        return app(V2UnivapayWebhookController::class)($request,$path ?? str_repeat('p',32))->status();
    }

    private function problem(callable $call,string $code,int $status): void
    {
        try { $call(); self::fail('Expected rejection.'); }
        catch (V2FincodeException $e) { self::assertSame($code,$e->errorCode); self::assertSame($status,$e->status); }
    }
}

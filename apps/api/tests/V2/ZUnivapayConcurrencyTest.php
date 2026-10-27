<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2UnivapayPaymentService;
use App\Domain\Payment\V2\Services\V2UnivapayWebhookService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;
use Tests\V2\Fixtures\UnivapayFixtures;

final class ZUnivapayConcurrencyTest extends TestCase
{
    use UnivapayFixtures, MemberRankAccountingFixture;

    protected function tearDown(): void
    {
        // Forked commits cannot be rolled back by DatabaseTransactions. Match the existing isolated race harness.
        try {
            DB::reconnect();
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        } finally {
            parent::tearDown();
        }
    }

    public function test_browser_webhook_and_recovery_race_grants_rank_and_mail_only_once(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'pcntl is required for payment races.');
        $this->configureRankFixture(); $this->enableRankFixture(); $this->configureUnivapay('live'); Mail::fake();
        [$user,$payment]=$this->univapayPayment(); $charge=$this->charge($payment); $this->fakeCharge($charge);
        $event=(string)Str::uuid();
        app(V2UnivapayWebhookService::class)->receive(['id'=>$event,'event'=>'charge_finished','data'=>$charge]);
        $this->rankClock(now()->addMinutes(5)->toIso8601String());
        $this->parallel([
            fn () => app(V2UnivapayPaymentService::class)->report(User::query()->findOrFail($user->id),$payment->public_id,['charge_id'=>$charge['id']]),
            fn () => app(V2UnivapayWebhookService::class)->work(),
            fn () => app(V2UnivapayPaymentService::class)->reconcile($charge['id'],(int)$payment->id),
        ]);
        self::assertSame(1,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        self::assertSame(1,DB::table('mail_deliveries')->where('source_public_id',$payment->public_id)->count());
        self::assertSame(1,DB::table('member_rank_point_entries')->where('payment_id',$payment->id)->count());
        self::assertSame(1,DB::table('member_rank_purchase_bonuses')->where('payment_id',$payment->id)->count());
        self::assertSame(1000,(int)DB::table('wallets')->where('user_id',$user->id)->value('paid_balance'));
        self::assertSame('delivered',DB::table('outbox_messages')->where('deduplication_key','univapay.receipt:'.$event)->value('status'));
        $other=$this->charge($payment); $this->fakeCharge($other);
        $this->parallel([
            fn () => app(V2UnivapayPaymentService::class)->reconcile($other['id'],(int)$payment->id),
            fn () => app(V2UnivapayPaymentService::class)->reconcile($other['id'],(int)$payment->id),
        ]);
        self::assertSame(1,DB::table('payment_point_grants')->where('payment_id',$payment->id)->count());
        self::assertSame(2,DB::table('payment_progress_events')->where('payment_id',$payment->id)->whereRaw("detail->>'match' = 'duplicate_charge'")->count());
    }

    private function parallel(array $workers): void
    {
        $paths=[]; $children=[]; $start=microtime(true)+0.3;
        DB::disconnect();
        foreach ($workers as $index=>$worker) {
            $path=sys_get_temp_dir().'/pay2-'.Str::uuid().'.json'; $paths[]=$path;
            $pid=pcntl_fork(); self::assertNotSame(-1,$pid);
            if ($pid===0) {
                DB::purge(); DB::reconnect();
                while (microtime(true)<$start) usleep(1000);
                try { $worker(); $result='success'; }
                catch (\Throwable $e) { $result=$e::class; }
                file_put_contents($path,json_encode($result)); DB::disconnect(); exit(0);
            }
            $children[]=$pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid,$status); self::assertSame(0,pcntl_wexitstatus($status)); }
        DB::reconnect();
        foreach ($paths as $path) { $result=json_decode(file_get_contents($path),true); unlink($path); self::assertSame('success',$result); }
    }
}

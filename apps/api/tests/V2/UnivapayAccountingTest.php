<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Payment\V2\Services\V2UnivapayPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;
use Tests\V2\Fixtures\UnivapayFixtures;

final class UnivapayAccountingTest extends TestCase
{
    use DatabaseTransactions, UnivapayFixtures, MemberRankAccountingFixture;

    public function test_test_classification_excludes_rank_and_live_manual_refund_reuses_m9(): void
    {
        $this->configureRankFixture(); $this->enableRankFixture();
        foreach (['test','live'] as $mode) {
            $this->configureUnivapay($mode); [$user,$payment]=$this->univapayPayment();
            $charge=$this->charge($payment); $this->fakeCharge($charge);
            app(V2UnivapayPaymentService::class)->report($user,$payment->public_id,['charge_id'=>$charge['id']]);
            self::assertSame($mode==='live'?1:0,DB::table('member_rank_point_entries')->where('payment_id',$payment->id)->count());
            self::assertSame($mode==='live'?1:0,DB::table('member_rank_purchase_bonuses')->where('payment_id',$payment->id)->count());
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            $context=$this->rankAdmin(); $service=app(V2AdminPaymentAdjustmentService::class);
            $prepared=$service->write($context,$payment->public_id,'refund',['reason'=>'Synthetic refund'],(string)Str::uuid());
            $complete=$service->write($context,$payment->public_id,'complete',[
                'reason'=>'Synthetic provider confirmed','provider_refund_confirmed'=>true,'provider_adjustment_id'=>'synthetic-refund'],(string)Str::uuid(),$prepared['data']['id']);
            self::assertSame('succeeded',$complete['data']['status']);
            self::assertSame(0,(int)DB::table('wallets')->where('user_id',$user->id)->value('paid_balance'));
            self::assertSame(0,(int)DB::table('wallets')->where('user_id',$user->id)->value('free_balance'));
            Http::assertNothingSent();
        }
    }
}

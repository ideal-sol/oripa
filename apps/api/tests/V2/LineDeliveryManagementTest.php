<?php

namespace Tests\V2;

use App\Domain\Line\Services\V2LineDeliveryAdmin;
use App\Domain\Line\Services\V2LineDeliveryService;
use App\Domain\Line\Services\V2LineDeliverySegment;
use App\Domain\Line\Services\V2LineMediaManager;
use App\Domain\Line\Services\V2LineMediaStorage;
use App\Domain\Line\Services\V2LineDeliveryMedia;
use App\Domain\Line\ValueObjects\V2LineRecipient;
use App\Domain\Line\Enums\V2LineRecipientGroup;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestsDatabaseTimeZones;
use Tests\TestCase;

final class LineDeliveryManagementTest extends TestCase
{
    use TestsDatabaseTimeZones;

    use MemberRankAccountingFixture;
    private string $schema;
    private array $connection;
    private object $context;
    private object $bot;
    protected function setUp(): void
    {
        parent::setUp(); self::assertTrue(app()->environment('testing')); Http::preventStrayRequests();
        $this->schema='d5_test_'.bin2hex(random_bytes(6)); $this->connection=config('database.connections.pgsql');
        DB::statement('CREATE SCHEMA '.$this->schema); config(['database.connections.pgsql.search_path'=>$this->schema]); DB::purge('pgsql');
        self::assertSame(0,Artisan::call('migrate',['--path'=>'database/migrations-v2','--force'=>true]));
        $this->configureRankFixture(); Carbon::setTestNow(); CarbonImmutable::setTestNow();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32))]); $this->enableRankFixture(); $this->context=$this->rankAdmin();
        DB::table('admin_sessions')->where('admin_id',$this->context->adminId)->update(['mfa_verified_at'=>null]);
        DB::table('line_bots')->where('is_active',true)->update(['is_active'=>false]);
        $id=DB::table('line_bots')->insertGetId(['public_id'=>(string)Str::uuid(),'name'=>'D5 synthetic','channel_id'=>'123456789',
            'channel_secret_ciphertext'=>Crypt::encryptString('synthetic-only'),'is_active'=>true,'connection_status'=>'succeeded','connected_at'=>now()]);
        $this->bot=DB::table('line_bots')->where('id',$id)->first();
    }
    protected function tearDown(): void
    {
        while(DB::transactionLevel()>0) DB::rollBack(); DB::purge('pgsql'); config(['database.connections.pgsql'=>$this->connection]);
        DB::statement('DROP SCHEMA '.$this->schema.' CASCADE'); Carbon::setTestNow(); CarbonImmutable::setTestNow(); parent::tearDown();
    }
    private function input(): array
    {
        return ['title'=>'Synthetic delivery','category'=>'promotional','recipient_group'=>'all_friends','segment'=>[],
            'messages'=>[['type'=>'text','text'=>'Hello %username%']],'pacing'=>['push_every'=>10,'push_wait_seconds'=>5,'multicast_wait_seconds'=>0],
            'mode'=>'draft','scheduled_at'=>null,'expected_revision'=>0,'request_key'=>(string)Str::uuid()];
    }
    #[DataProvider('databaseTimeZones')]
    public function test_quota_view_initializes_estimated_usage_and_distinguishes_failure_and_halt(string $zone): void
    {
        $this->assertDatabaseTimeZone($zone);
        $service=app(V2LineDeliveryAdmin::class);
        self::assertSame('initialization_pending',$service->quota()['automation_state']);
        Http::fake(['*'=>Http::response([],503)]);
        self::assertSame('provider_error',$service->quota(true)['automation_state']);
        self::assertFalse(DB::table('line_quota_accounts')->where('line_bot_id',$this->bot->id)->exists());
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(['*/oauth2/v3/token'=>Http::response(['access_token'=>'synthetic','expires_in'=>900]),
            '*/quota'=>Http::response(['type'=>'limited','value'=>5000]),'*/consumption'=>Http::response(['totalUsage'=>300])]);
        $state=$service->quota(true); self::assertSame('initialized',$state['automation_state']);
        self::assertSame('system_initial',$state['periods'][0]['baseline_origin']);
        self::assertSame(300,$state['periods'][0]['opening_used']); self::assertSame(4700,$state['periods'][0]['remaining']);
        self::assertNotNull($state['last_checked_at']);
        $input=$this->input(); $input['mode']='scheduled'; $input['scheduled_at']=now()->addHour()->toIso8601String();
        $scheduled = $service->save($this->context,null,$input)['data'];
        self::assertSame('scheduled', $scheduled['status']);
        $due = CarbonImmutable::parse($input['scheduled_at']);
        $this->assertStoredInstant($due, $scheduled['scheduled_at']);
        $input['scheduled_at'] = $due->utc()->toIso8601String();
        self::assertSame($scheduled['id'], $service->save($this->context,null,$input)['data']['id']);
        $id = DB::table('line_deliveries')->where('public_id',$scheduled['id'])->value('id');
        app(V2LineDeliveryService::class)->resolve($id);
        self::assertSame('scheduled', DB::table('line_deliveries')->where('id',$id)->value('status'));
        $service->quotaWrite($this->context,'halt',['bot_id'=>$this->bot->public_id,'authentication_revision'=>1,'evidence_id'=>(string)Str::uuid()]);
        self::assertSame('halted',$service->quota(true)['automation_state']);
        self::assertSame(300,DB::table('line_quota_periods')->where('line_bot_id',$this->bot->id)->value('opening_used'));
        Http::assertNotSent(fn ($request)=>preg_match('~/message/(push|multicast)$~',$request->url())===1);
    }
    #[DataProvider('databaseTimeZones')]
    public function test_create_edit_duplicate_cancel_and_stale_revision_and_no_send(string $zone): void
    {
        $this->assertDatabaseTimeZone($zone);
        $this->freezeSecond();
        $s=app(V2LineDeliveryAdmin::class); $s->authorize($this->context,true); $input=$this->input();
        $a=$s->save($this->context,null,$input); $id=$a['data']['id']; self::assertSame('draft',$a['data']['status']);
        self::assertSame($id,$s->save($this->context,null,$input)['data']['id']);
        self::assertNull($a['data']['scheduled_at']);
        $this->assertStoredInstant(now(), DB::table('line_deliveries')->where('public_id',$id)->value('updated_at'));
        $input['expected_revision']=1; $input['segment']=['points_min'=>1];
        self::assertSame(2,$s->save($this->context,$id,$input)['data']['revision']);
        try { $s->save($this->context,$id,$input); self::fail('Stale revision accepted'); } catch(\RuntimeException $e) { self::assertStringContainsString('conflict',$e->getMessage()); }
        $copy=$s->action($this->context,$id,'duplicate',['expected_revision'=>2,'request_key'=>(string)Str::uuid()]);
        self::assertNotSame($id,$copy['data']['id']); self::assertSame(1,$copy['data']['segment']->points_min);
        self::assertSame('cancelled',$s->action($this->context,$id,'cancel',['expected_revision'=>2,'request_key'=>(string)Str::uuid()])['data']['status']);
        self::assertCount(2,$s->listing(0)['data']); self::assertSame(0,DB::table('line_delivery_batches')->count()); Http::assertNothingSent();
    }
    public function test_unknown_friend_attributes_are_never_a_match_and_jst_dates_are_inclusive(): void
    {
        $user=$this->rankUser(); $this->seedRankState($user,1);
        $member=[new V2LineRecipient($this->bot->id,1,'line_friendships',1,$user->id,V2LineRecipientGroup::LinkedMembers),(object)['followed_at'=>'2026-10-01T15:00:00Z']];
        $unknown=[new V2LineRecipient($this->bot->id,1,'line_recovered_friends',2,null,V2LineRecipientGroup::UnlinkedFriends),(object)['observed_at_ms'=>now()->getTimestampMs()]];
        $s=app(V2LineDeliverySegment::class); $now=CarbonImmutable::now();
        self::assertCount(2,$s->filter([$member,$unknown],[],$now));
        self::assertCount(1,$s->filter([$member,$unknown],['points_min'=>0,'purchase_max'=>0,'rank_levels'=>[1,2]],$now));
        self::assertCount(1,$s->filter([$member,$unknown],['followed_from'=>'2026-10-02','followed_to'=>'2026-10-02'],$now));
        self::assertCount(0,$s->filter([$member,$unknown],['followed_to'=>'2026-10-01'],$now));
        self::assertCount(0,$s->filter([$member,$unknown],['purchase_within_days'=>10],$now));
        self::assertCount(1,$s->filter([$member,$unknown],['purchase_absent_days'=>10],$now));
    }
    public function test_segment_uses_real_payment_amount_not_rank_transfer_points(): void
    {
        $user=$this->rankUser(); DB::beginTransaction(); $this->settleRankPayment($user,1000); DB::commit();
        $pair=[new V2LineRecipient($this->bot->id,1,'line_friendships',1,$user->id,V2LineRecipientGroup::LinkedMembers),(object)['followed_at'=>now()]];
        $s=app(V2LineDeliverySegment::class);
        self::assertCount(1,$s->filter([$pair],['purchase_min'=>1000,'purchase_max'=>1000,'purchase_within_days'=>1],CarbonImmutable::now()));
        self::assertCount(0,$s->filter([$pair],['purchase_min'=>1001],CarbonImmutable::now()));
    }
    public function test_terminal_segment_and_creation_identity_are_immutable(): void
    {
        $s=app(V2LineDeliveryAdmin::class); $d=$s->save($this->context,null,$this->input());
        $s->action($this->context,$d['data']['id'],'cancel',['expected_revision'=>1,'request_key'=>(string)Str::uuid()]);
        foreach([['segment'=>'{"points_min":1}'],['create_request_key'=>(string)Str::uuid()]] as $change) {
            try { DB::table('line_deliveries')->where('public_id',$d['data']['id'])->update($change); self::fail('Immutable delivery changed'); } catch(\Illuminate\Database\QueryException) { self::assertTrue(true); }
        }
    }
    public function test_refund_and_chargeback_reduce_commercial_total_without_erasing_purchase_date(): void
    {
        $user=$this->rankUser();
        $payment=DB::transaction(fn()=>$this->settleRankPayment($user,1000));
        $second=DB::transaction(fn()=>$this->settleRankPayment($user,2000));
        $adjust=app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
        $prepared=$adjust->write($this->context,$payment->public_id,'refund',['reason'=>'Synthetic refund'],(string)Str::uuid7());
        $adjust->write($this->context,$payment->public_id,'complete',['reason'=>'Synthetic completion','provider_refund_confirmed'=>true],(string)Str::uuid7(),$prepared['data']['id']);
        $pair=[new V2LineRecipient($this->bot->id,1,'line_friendships',1,$user->id,V2LineRecipientGroup::LinkedMembers),(object)['followed_at'=>now()]];
        $s=app(V2LineDeliverySegment::class);
        self::assertCount(1,$s->filter([$pair],['purchase_min'=>2000,'purchase_max'=>2000,'purchase_within_days'=>1],CarbonImmutable::now()));
        $adjust->write($this->context,$second->public_id,'chargeback',['reason'=>'Synthetic chargeback'],(string)Str::uuid7());
        self::assertCount(1,$s->filter([$pair],['purchase_min'=>0,'purchase_max'=>0,'purchase_within_days'=>1],CarbonImmutable::now()));
        self::assertCount(0,$s->filter([$pair],['purchase_min'=>1],CarbonImmutable::now()));
    }

    #[DataProvider('databaseTimeZones')]
    public function test_preview_and_resolve_share_segment_and_now_enqueues_without_delivery_http(string $zone): void
    {
        $this->assertDatabaseTimeZone($zone);
        $user=$this->rankUser();
        $registry=app(\App\Domain\Line\Services\V2LineRecoveredFriendRegistry::class);
        foreach(['a','b'] as $letter) $registry->record($this->bot->id,1,'U'.str_repeat($letter,32),now()->getTimestampMs());
        \App\Models\V2\ExternalIdentityAccount::query()->create(['user_id'=>$user->id,'provider'=>'line','issuer'=>'https://access.line.me',
            'subject_hash'=>DB::table('line_recovered_friends')->orderBy('id')->value('subject_hash'),'linked_at'=>now(),'last_authenticated_at'=>now()]);
        $s=app(V2LineDeliveryAdmin::class); $q=app(\App\Domain\Line\Services\V2LineDeliveryQuota::class);
        $cut = now()->subSecond()->startOfSecond();
        $s->quotaWrite($this->context,'baseline',['bot_id'=>$this->bot->public_id,'authentication_revision'=>1,'month'=>$q->month(CarbonImmutable::now()),
            'opening_used'=>0,'approved_limit'=>100,'evidence_id'=>(string)Str::uuid(),'verified_at'=>$cut->toIso8601String(),
            'confirmation'=>'課金対象送信の一元管理・正確な基準値・未確定の外部送信なしを確認しました']);
        $this->assertStoredInstant($cut, DB::table('line_quota_periods')->value('baseline_cut_at'));
        $input=$this->input(); $input['segment']=['points_min'=>0]; $input['mode']='now';
        Http::fake(['*/oauth2/v3/token'=>Http::response(['access_token'=>'synthetic-token','expires_in'=>900]),
            '*/quota'=>Http::response(['type'=>'limited','value'=>100]),'*/consumption'=>Http::response(['totalUsage'=>0])]);
        $preview=$s->preview(array_intersect_key($input,array_flip(['category','recipient_group','segment','messages','pacing'])));
        self::assertSame(1,$preview['recipient_count']);
        $this->freezeSecond();
        $saved=$s->save($this->context,null,$input); self::assertSame('scheduled',$saved['data']['status']);
        $legacyHash = hash('sha256', json_encode([
            $input['category'], $input['recipient_group'], $input['messages'], now()->toIso8601String(),
            $input['pacing'], $input['title'], $input['segment'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame($legacyHash, DB::table('line_deliveries')->where('public_id',$saved['data']['id'])->value('create_request_hash'));
        $this->travel(1)->seconds();
        self::assertSame($saved['data']['id'],$s->save($this->context,null,$input)['data']['id']);
        Http::assertNotSent(fn($r)=>preg_match('~/message/(push|multicast)$~',$r->url())===1);
        $id=DB::table('line_deliveries')->where('public_id',$saved['data']['id'])->value('id');
        try {
            $s->save($this->context,null,array_replace($input,['title'=>'Different title']));
            self::fail('Conflicting creation key was accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('request conflict', $exception->getMessage());
        }
        app(V2LineDeliveryService::class)->resolve($id);
        app(V2LineDeliveryService::class)->resolve($id);
        self::assertSame(1,DB::table('line_delivery_batches')->count());
        self::assertSame($preview['recipient_count'],DB::table('line_delivery_recipients')->count());
        self::assertSame($user->id,DB::table('line_delivery_recipients')->value('user_id'));
        self::assertSame(1,$q->reserved($this->bot->id));
        self::assertNotNull($s->detail($saved['data']['id'])['estimated_dispatch_end']);
        $legacyInput = array_replace($input, ['request_key' => (string) Str::uuid()]);
        $legacyId = $s->save($this->context, null, $legacyInput)['data']['id'];
        $legacyRow = DB::table('line_deliveries')->where('public_id', $legacyId);
        $originalHash = (clone $legacyRow)->value('create_request_hash');
        $shifted = CarbonImmutable::now()->shiftTimezone('UTC');
        // A pre-fix UTC writer bound the application wall time without its offset.
        (clone $legacyRow)->update(['scheduled_at' => $shifted->toIso8601String()]);
        $this->travel(1)->seconds();
        self::assertSame($legacyId, $s->save($this->context, null, $legacyInput)['data']['id']);
        $replayed = $legacyRow->sole();
        self::assertSame($originalHash, $replayed->create_request_hash);
        $this->assertStoredInstant($shifted, $replayed->scheduled_at);
        self::assertSame(2, DB::table('line_deliveries')->count());
        self::assertSame(1, DB::table('line_delivery_batches')->count());
        self::assertSame(1, $q->reserved($this->bot->id));
        try { DB::table('line_quota_periods')->where('line_bot_id',$this->bot->id)->update(['baseline_cut_at'=>now()->subHour()]); self::fail('Baseline evidence rewritten'); }
        catch (\Illuminate\Database\QueryException) { self::assertTrue(true); }
        Http::assertNotSent(fn($r)=>preg_match('~/message/(push|multicast)$~',$r->url())===1);
        $s->quotaWrite($this->context,'halt',['bot_id'=>$this->bot->public_id,'authentication_revision'=>1,'evidence_id'=>(string)Str::uuid()]);
        self::assertTrue($s->quota()['halted']); self::assertSame(0,$s->quota()['periods'][0]['remaining']);
    }
    public function test_missing_quota_cannot_schedule_and_past_time_is_invalid(): void
    {
        $s=app(V2LineDeliveryAdmin::class); $input=$this->input(); $input['mode']='scheduled'; $input['scheduled_at']=now()->subHour()->toIso8601String();
        try { $s->save($this->context,null,$input); self::fail('Past schedule accepted'); } catch(\InvalidArgumentException) { self::assertTrue(true); }
        $input['scheduled_at']=now()->addHour()->toIso8601String();
        $this->expectException(\RuntimeException::class); $s->save($this->context,null,$input);
    }
    #[DataProvider('databaseTimeZones')]
    public function test_media_manifest_is_verified_and_immutable_and_duplicate_finalize_rejected(string $zone): void
    {
        $this->assertDatabaseTimeZone($zone);
        config(['v2_assets.public_base_url'=>'https://cdn.example.test']);
        $unverified='https://cdn.example.test/line-delivery/'.Str::uuid().'/original';
        try {
            app(\App\Domain\Line\Services\V2LineDeliveryMessages::class)->render([['type'=>'image','originalContentUrl'=>$unverified,'previewImageUrl'=>$unverified]]);
            self::fail('Unverified manifest accepted.');
        } catch (\Illuminate\Database\RecordNotFoundException) { self::assertTrue(true); }
        Http::assertNothingSent();
        $image=imagecreatetruecolor(1040,520); ob_start(); imagepng($image); $bytes=ob_get_clean(); imagedestroy($image);
        $storage=new class($bytes) extends V2LineMediaStorage {
            public array $published=[];
            public function __construct(private string $bytes) {}
            public function upload(array $f): array { return ['url'=>'https://upload.example.test/synthetic','headers'=>[]]; }
            public function download(array $f,string $p): void { file_put_contents($p,$this->bytes); }
            public function publish(string $key,string $path,string $mime): void { if(isset($this->published[$key])) throw new \RuntimeException; $this->published[$key]=hash_file('sha256',$path); }
        }; app()->instance(V2LineMediaStorage::class,$storage);
        $this->freezeSecond();
        $s=app(V2LineMediaManager::class); $i=$s->intent($this->context,['kind'=>'imagemap','original'=>['mime'=>'image/png','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)]]);
        $expires = CarbonImmutable::parse($i['expires_at']);
        self::assertTrue(now()->addMinutes(5)->eq($expires));
        $this->assertStoredInstant($expires, DB::table('line_media_intents')->where('id',$i['id'])->value('expires_at'));
        $this->travelTo($expires->subSecond());
        $a=$s->finalize($this->context,$i['id']);
        $this->assertStoredInstant($expires->subSecond(), DB::table('line_media_intents')->where('id',$i['id'])->value('finalized_at'));
        self::assertCount(5,$storage->published);
        app(V2LineDeliveryMedia::class)->assertVerified(['type'=>'imagemap','baseUrl'=>$a['original_url'],'height'=>520,'layout'=>'full']);
        try { DB::table('line_media_assets')->where('id',$i['id'])->delete(); self::fail('Verified asset deleted'); } catch(\Illuminate\Database\QueryException) { self::assertTrue(true); }
        try { DB::statement('TRUNCATE line_media_assets'); self::fail('Verified assets truncated'); } catch(\Illuminate\Database\QueryException) { self::assertTrue(true); }
        $expired=$s->intent($this->context,['kind'=>'image','original'=>['mime'=>'image/png','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)]]);
        $this->travelTo(CarbonImmutable::parse($expired['expires_at']));
        DB::table('admin_sessions')->where('admin_id',$this->context->adminId)->update(['mfa_verified_at'=>now()]);
        try { $s->finalize($this->context,$expired['id']); self::fail('Expired upload finalized'); } catch(\InvalidArgumentException) { self::assertTrue(true); }
        Carbon::setTestNow(); CarbonImmutable::setTestNow();
        self::assertCount(5,$storage->published);
        $this->expectException(\InvalidArgumentException::class); $s->finalize($this->context,$i['id']);
    }
    public function test_stale_mfa_allows_authorized_write(): void
    {
        DB::table('admin_sessions')->where('admin_id',$this->context->adminId)->update(['mfa_verified_at'=>now()->subHour()]);
        app(V2LineDeliveryAdmin::class)->authorize($this->context,true);
        self::assertSame('draft', app(V2LineDeliveryAdmin::class)->save($this->context,null,$this->input())['data']['status']);
    }

    public function test_four_media_formats_finalize_real_bytes_and_reject_changed_generation(): void
    {
        config(['v2_assets.public_base_url'=>'https://cdn.example.test']);
        $directory=sys_get_temp_dir().'/lineux-formats-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
        try {
            $image=imagecreatetruecolor(320,320); ob_start(); imagepng($image); $png=ob_get_clean(); imagedestroy($image);
            $video=$directory.'/video.mp4';
            file_put_contents($video, \Tests\Support\SyntheticLineVideo::bytes(320));
            $storage=new class extends V2LineMediaStorage {
                public array $bytes=[]; public array $published=[];
                public function upload(array $f): array { return ['url'=>'https://upload.example.test/synthetic','headers'=>[]]; }
                public function download(array $f,string $p): void { file_put_contents($p,$this->bytes[basename($f['key'])]); }
                public function publish(string $key,string $path,string $mime): void {
                    \PHPUnit\Framework\Assert::assertFileExists($path); $this->published[]=$key;
                }
            };
            app()->instance(V2LineMediaStorage::class,$storage); $service=app(V2LineMediaManager::class);
            $metadata=fn($bytes,$mime)=>['bytes'=>strlen($bytes),'mime'=>$mime,'sha256'=>hash('sha256',$bytes)];
            foreach (['image'=>2,'video'=>2,'imagemap'=>5,'carousel'=>1] as $kind=>$objects) {
                $storage->bytes=['original'=>$kind==='video' ? file_get_contents($video) : $png,'preview'=>$png]; $storage->published=[];
                $input=['kind'=>$kind,'original'=>$metadata($storage->bytes['original'],$kind==='video' ? 'video/mp4' : 'image/png')];
                if ($kind==='video') $input['preview']=$metadata($png,'image/png');
                $intent=$service->intent($this->context,$input);
                self::assertCount($kind==='video' ? 2 : 1,$intent['uploads']);
                $asset=$service->finalize($this->context,$intent['id']);
                self::assertSame($kind,$asset['kind']); self::assertCount($objects,$storage->published);
                self::assertTrue(DB::table('line_media_assets')->where('id',$intent['id'])->exists());
            }
            $intent=$service->intent($this->context,['kind'=>'image','original'=>$metadata($png,'image/png')]);
            DB::table('line_bots')->where('id',$this->bot->id)->update(['authentication_revision'=>2,'revision'=>2]);
            $this->expectException(\InvalidArgumentException::class); $service->finalize($this->context,$intent['id']);
        } finally { if (is_file($directory.'/video.mp4')) unlink($directory.'/video.mp4'); rmdir($directory); }
    }
}

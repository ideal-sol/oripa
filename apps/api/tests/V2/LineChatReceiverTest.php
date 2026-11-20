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
use Tests\TestCase;

final class LineChatReceiverTest extends TestCase
{
    use MemberRankAccountingFixture;
    private string $schema;
    private array $connection;
    private object $context;
    private object $bot;
    protected function setUp(): void
    {
        parent::setUp(); self::assertTrue(app()->environment('testing')); Http::preventStrayRequests();
        $this->schema='d6_test_'.bin2hex(random_bytes(6)); $this->connection=config('database.connections.pgsql');
        DB::statement('CREATE SCHEMA '.$this->schema); config(['database.connections.pgsql.search_path'=>$this->schema]); DB::purge('pgsql');
        self::assertSame(0,Artisan::call('migrate',['--path'=>'database/migrations-v2','--force'=>true]));
        $this->configureRankFixture(); Carbon::setTestNow(); CarbonImmutable::setTestNow();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32))]); $this->enableRankFixture(); $this->context=$this->rankAdmin(); DB::table('admin_sessions')->where('admin_id',$this->context->adminId)->update(['mfa_verified_at'=>null]);
        DB::table('line_bots')->where('is_active',true)->update(['is_active'=>false]);
        $id=DB::table('line_bots')->insertGetId(['public_id'=>(string)Str::uuid(),'name'=>'D6 synthetic','channel_id'=>'123456789',
            'channel_secret_ciphertext'=>Crypt::encryptString('synthetic-only'),'is_active'=>true,'connection_status'=>'succeeded','connected_at'=>now()]);
        $this->bot=DB::table('line_bots')->where('id',$id)->first();
    }
    protected function tearDown(): void
    {
        while(DB::transactionLevel()>0) DB::rollBack(); DB::purge('pgsql'); config(['database.connections.pgsql'=>$this->connection]);
        DB::statement('DROP SCHEMA '.$this->schema.' CASCADE'); Carbon::setTestNow(); CarbonImmutable::setTestNow(); parent::tearDown();
    }
    private function event(string $id = '123', string $type = 'text'): array
    {
        return ['type'=>'message','webhookEventId'=>'event-'.$id,'timestamp'=>now()->getTimestampMs(),
            'source'=>['type'=>'user','userId'=>'U'.str_repeat('a',32)],
            'message'=>['id'=>$id,'type'=>$type,'text'=>'Synthetic secret text','contentProvider'=>['type'=>'line']]];
    }
    private function receive(array $events, ?int $generation = null): void
    {
        app(\App\Domain\Line\Services\V2LineChatReceiver::class)->handle($events,$this->bot->id,$generation ?? $this->bot->authentication_revision);
    }
    private function unsend(array $event): array
    {
        return [...$event,'type'=>'unsend','webhookEventId'=>'unsend-'.$event['message']['id'],'unsend'=>['messageId'=>$event['message']['id']]];
    }
    public function test_duplicate_and_redelivery_keep_one_encrypted_history_and_no_invented_member(): void
    {
        $e=$this->event(); $this->receive([$e,$e,[...$e,'webhookEventId'=>'redelivery','deliveryContext'=>['isRedelivery'=>true]]]);
        self::assertSame(1,DB::table('line_chat_messages')->count()); self::assertSame(2,DB::table('line_chat_events')->count());
        $m=DB::table('line_chat_messages')->first();
        self::assertStringNotContainsString('Synthetic secret text',$m->body_ciphertext);
        self::assertSame('Synthetic secret text',json_decode(Crypt::decryptString($m->body_ciphertext),true)['text']);
        self::assertNull(DB::table('line_conversations')->value('user_id'));
        self::assertSame(0,DB::table('line_delivery_batches')->count()); Http::assertNothingSent();
    }
    public function test_list_preview_is_bounded_and_unsend_never_decrypts_the_removed_body(): void
    {
        $event = $this->event(); $event['message']['text'] = str_repeat('あ', 100);
        $this->receive([$event]);
        $service = app(\App\Domain\Line\Services\V2LineChatAdmin::class);
        self::assertSame(str_repeat('あ', 80), $service->listing($this->context)['data'][0]['summary']);
        $this->receive([$this->unsend($event)]);
        self::assertSame('取消済み', $service->listing($this->context)['data'][0]['summary']);
        self::assertNull(DB::table('line_chat_messages')->value('body_ciphertext'));
        Http::assertNothingSent();
    }
    public function test_unsend_before_receive_is_a_permanent_tombstone_and_cross_subject_collision_is_rejected(): void
    {
        $e=$this->event(); $this->receive([$this->unsend($e),$e,$this->unsend($e)]);
        self::assertSame(1,DB::table('line_chat_messages')->count());
        $m=DB::table('line_chat_messages')->first(); self::assertTrue($m->unsent); self::assertNull($m->body_ciphertext);
        $other=$this->event('456'); $this->receive([$other]);
        $wrong=$this->unsend($other); $wrong['source']['userId']='U'.str_repeat('b',32); $this->receive([$wrong]);
        self::assertFalse(DB::table('line_chat_messages')->where('line_message_id','456')->value('unsent'));
        Http::assertNothingSent();
    }
    public function test_group_stale_generation_and_inactive_bot_are_without_effect(): void
    {
        $e=$this->event(); $group=$e; $group['source']['type']='group'; $this->receive([$group]);
        $this->receive([$e],999); DB::table('line_bots')->where('id',$this->bot->id)->update(['is_active'=>false]); $this->receive([$e]);
        self::assertSame(0,DB::table('line_conversations')->count()); Http::assertNothingSent();
    }
    public function test_out_of_order_messages_preserve_latest_timestamp_and_minimal_safe_types(): void
    {
        $new=$this->event('100','sticker'); $new['message']+=['packageId'=>'1','stickerId'=>'2','unsafe'=>'discard'];
        $old=$this->event('101','location'); $old['timestamp']-=1000; $old['message']+=['title'=>'Place','latitude'=>35.12345,'longitude'=>139.12345];
        $this->receive([$new,$old,$this->event('102','unknown')]);
        $bodies=DB::table('line_chat_messages')->orderBy('id')->get()->map(fn($m)=>json_decode(Crypt::decryptString($m->body_ciphertext),true))->all();
        self::assertSame(['packageId'=>'1','stickerId'=>'2'],$bodies[0]); self::assertSame(['title'=>'Place','address'=>''],$bodies[1]);
        self::assertSame([], $bodies[2]);
        self::assertGreaterThanOrEqual($new['timestamp'],CarbonImmutable::parse(DB::table('line_conversations')->value('last_message_at'))->getTimestampMs());
    }
    public function test_media_is_claimed_once_outside_transaction_and_pending_is_not_success(): void
    {
        $fake=new class extends \App\Domain\Line\Services\V2LineChatMedia {
            public int $calls=0;
            public function receive(object $bot,object $message,int $deadline): array {
                \PHPUnit\Framework\Assert::assertSame(0,DB::transactionLevel()); $this->calls++; return ['media_state'=>'pending_external'];
            }
        }; app()->instance(\App\Domain\Line\Services\V2LineChatMedia::class,$fake);
        $e=$this->event('123','video'); $this->receive([$e,$e]);
        self::assertSame(1,$fake->calls); self::assertSame('pending_external',DB::table('line_chat_messages')->value('media_state'));
        self::assertNull(DB::table('line_chat_messages')->value('media_key'));
        $external=$this->event('456','image'); $external['message']['contentProvider']=['type'=>'external','originalContentUrl'=>'http://127.0.0.1/private'];
        $this->receive([$external]); self::assertSame(1,$fake->calls);
        self::assertSame('unavailable',DB::table('line_chat_messages')->where('line_message_id','456')->value('media_state'));
    }
    public function test_cancel_during_media_fetch_never_restores_body_or_attachment(): void
    {
        $e=$this->event('123','image'); $cancel=$this->unsend($e);
        $fake=new class($cancel) extends \App\Domain\Line\Services\V2LineChatMedia {
            public function __construct(private array $cancel) {}
            public function receive(object $bot,object $message,int $deadline): array {
                app(\App\Domain\Line\Services\V2LineChatReceiver::class)->handle([$this->cancel],$bot->id,$bot->authentication_revision);
                return ['media_state'=>'unavailable'];
            }
        }; app()->instance(\App\Domain\Line\Services\V2LineChatMedia::class,$fake); $this->receive([$e]);
        $m=DB::table('line_chat_messages')->first(); self::assertTrue($m->unsent); self::assertNull($m->body_ciphertext); self::assertNull($m->media_key); self::assertSame('cancelled',$m->media_state);
    }
    public function test_history_rewrite_delete_and_truncate_are_rejected(): void
    {
        $this->receive([$this->event()]);
        foreach (['UPDATE line_chat_messages SET body_ciphertext = \'replacement\'', 'DELETE FROM line_chat_messages', 'TRUNCATE line_chat_messages CASCADE'] as $sql) {
            try { DB::statement($sql); self::fail('History mutation allowed'); } catch (\Illuminate\Database\QueryException) { self::assertTrue(true); }
        }
        self::assertSame(1,DB::table('line_chat_messages')->count());
    }

    public function test_private_attachment_is_encrypted_and_authenticated_and_unsend_hides_it(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGZkAAAAASUVORK5CYII=');
        Http::fake(function($request,$options) use ($png) {
            if (str_ends_with($request->url(),'/oauth2/v3/token')) return Http::response(['access_token'=>'synthetic','expires_in'=>900]);
            self::assertSame('https://api-data.line.me/v2/bot/message/123/content',$request->url());
            self::assertSame(0,DB::transactionLevel()); self::assertFalse($options['allow_redirects']);
            self::assertFalse($options['decode_content']);
            foreach ([fn()=>($options['on_headers'])(new \GuzzleHttp\Psr7\Response(200,['Content-Length'=>'8388609'])),fn()=>($options['progress'])(0,8388609)] as $limit) {
                try { $limit(); self::fail('Streaming limit missing'); } catch (\RuntimeException) { self::assertTrue(true); }
            }
            return Http::response($png,200,['Content-Type'=>'image/png']);
        });
        $e=$this->event('123','image'); $this->receive([$e]);
        $m=DB::table('line_chat_messages')->first(); self::assertSame('stored',$m->media_state);
        $disk=\Illuminate\Support\Facades\Storage::disk('local'); self::assertNotSame($png,$disk->get($m->media_key));
        self::assertSame($png,Crypt::decryptString($disk->get($m->media_key)));
        $service=app(\App\Domain\Line\Services\V2LineChatAdmin::class); $id=DB::table('line_conversations')->value('public_id');
        self::assertSame($png,$service->attachment($this->context,$id,$m->public_id)['body']);
        $this->receive([$this->unsend($e)]);
        self::assertNull($service->detail($this->context,$id)['messages'][0]['body']);
        self::assertFalse($service->detail($this->context,$id)['messages'][0]['has_attachment']);
        $this->expectException(\Illuminate\Database\RecordNotFoundException::class); $service->attachment($this->context,$id,$m->public_id);
    }

    public function test_read_watermark_does_not_consume_later_messages_and_status_uses_revision(): void
    {
        $this->receive([$this->event('1')]); $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class);
        $id=DB::table('line_conversations')->value('public_id'); $first=DB::table('line_chat_messages')->value('id');
        $this->receive([$this->event('2')]); $s->markRead($this->context,$id,$first); $s->markRead($this->context,$id,$first);
        self::assertSame(1,$s->detail($this->context,$id)['conversation']['unread_count']);
        $s->status($this->context,$id,'closed',1); self::assertSame('closed',$s->detail($this->context,$id)['conversation']['status']);
        $this->expectException(\RuntimeException::class); $s->status($this->context,$id,'open',1);
    }

    public function test_template_revisions_category_restrict_and_copy_are_independent(): void
    {
        $s=app(\App\Domain\Line\Services\V2LineTemplates::class);
        $base=['bot_id'=>$this->bot->public_id,'expected_revision'=>0,'name'=>'Synthetic'];
        $cat=$s->save($this->context,'categories',null,$base+['sort_order'=>1]);
        $input=$base+['category_id'=>$cat['id'],'messages'=>[['type'=>'text','text'=>'Hello %username%']]];
        $template=$s->save($this->context,'templates',null,$input);
        $copy=$s->listing($this->context)['data'][0]['messages'];
        $s->save($this->context,'templates',$template['id'],[...$input,'expected_revision'=>1,'messages'=>[['type'=>'text','text'=>'Changed']]]);
        self::assertSame('Hello %username%',$copy[0]['text']);
        try { $s->save($this->context,'templates',$template['id'],[...$input,'expected_revision'=>1]); self::fail('Stale update accepted'); } catch (\RuntimeException) { self::assertTrue(true); }
        try { $s->save($this->context,'categories',$cat['id'],[...$base,'expected_revision'=>1],true); self::fail('Used category deleted'); } catch (\RuntimeException) { self::assertTrue(true); }
        $s->save($this->context,'templates',$template['id'],[...$base,'expected_revision'=>2],true);
        $s->save($this->context,'categories',$cat['id'],[...$base,'expected_revision'=>1],true);
        self::assertCount(0,$s->listing($this->context)['categories']); Http::assertNothingSent();
    }

    public function test_unlinked_reply_uses_one_transactional_batch_and_quota_and_later_link_is_same_conversation(): void
    {
        $subject='U'.str_repeat('a',32);
        app(\App\Domain\Line\Services\V2LineRecoveredFriendRegistry::class)->record($this->bot->id,1,$subject,now()->getTimestampMs());
        $q=app(\App\Domain\Line\Services\V2LineDeliveryQuota::class);
        $q->confirmBaseline($this->context,$this->bot->id,1,$q->month(app(\App\Domain\Line\Services\V2LineDeliverySender::class)->clock()),0,10,(string)Str::uuid(),
            '課金対象送信の一元管理・正確な基準値・未確定の外部送信なしを確認しました');
        $this->receive([$this->event()]); $id=DB::table('line_conversations')->value('public_id');
        $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class); $key=(string)Str::uuid(); $messages=[['type'=>'text','text'=>'Hello %username% %points%']];
        // The existing reply timestamp contract is seconds, while received events
        // retain milliseconds. Give this outbound fixture a strictly later time.
        Carbon::setTestNow(now()->addSeconds(2)); CarbonImmutable::setTestNow(Carbon::now());
        $mid=$s->reply($this->context,$id,$messages,$key); self::assertSame($mid,$s->reply($this->context,$id,$messages,$key));
        self::assertSame('Hello %username% %points%', $s->listing($this->context)['data'][0]['summary']);
        self::assertSame(1,DB::table('line_delivery_batches')->count()); self::assertSame(1,DB::table('line_quota_charges')->count());
        self::assertSame('transactional',DB::table('line_delivery_batches')->value('category')); self::assertNull(DB::table('line_delivery_recipients')->value('user_id'));
        try { $s->reply($this->context,$id,[['type'=>'text','text'=>'Changed']],$key); self::fail('Key collision accepted'); } catch (\RuntimeException) { self::assertTrue(true); }
        $user=$this->rankUser();
        \App\Models\V2\ExternalIdentityAccount::query()->create(['user_id'=>$user->id,'provider'=>'line','issuer'=>'https://access.line.me',
            'subject_hash'=>DB::table('line_conversations')->value('subject_hash'),'linked_at'=>now(),'last_authenticated_at'=>now()]);
        self::assertSame($user->public_id,$s->detail($this->context,$id)['conversation']['member_id']);
        $user->forceFill(['display_name'=>null,'full_name'=>'会員氏名'])->save();
        self::assertSame('会員氏名',$s->detail($this->context,$id)['conversation']['name']);
        $user->forceFill(['display_name'=>'LINEプロフィール名'])->save();
        self::assertSame('LINEプロフィール名',$s->detail($this->context,$id)['conversation']['name']);
        self::assertSame(1,DB::table('line_conversations')->count());
        $s->reply($this->context,$id,$messages,(string)Str::uuid());
        self::assertSame($user->id,DB::table('line_delivery_recipients')->orderByDesc('id')->value('user_id'));
        Http::assertNothingSent();
        Http::fake(['*/oauth2/v3/token'=>Http::response(['access_token'=>'synthetic','expires_in'=>900]),
            '*/quota'=>Http::response(['type'=>'limited','value'=>10]),'*/consumption'=>Http::response(['totalUsage'=>0]),
            '*/push'=>Http::response([],200)]);
        $batch=DB::table('line_delivery_batches')->orderByDesc('id')->first();
        app(\App\Domain\Line\Services\V2LineDeliverySender::class)->send($batch->id);
        self::assertSame(2,Http::recorded(fn($r)=>$r->method()==='GET' && str_contains($r->url(),'/v2/bot/message/quota'))->count());
        self::assertSame('accepted',DB::table('line_delivery_batches')->where('id',$batch->id)->value('status'));
        fwrite(STDOUT,"\nQUOTA_MEASURE chat_admission=0 chat_dispatch=2\n");
    }

    public function test_missing_quota_rolls_back_reply_and_unknown_friend_cannot_send(): void
    {
        $this->receive([$this->event()]); $id=DB::table('line_conversations')->value('public_id');
        $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class);
        foreach ([false,true] as $observed) {
            if ($observed) app(\App\Domain\Line\Services\V2LineRecoveredFriendRegistry::class)->record($this->bot->id,1,'U'.str_repeat('a',32),now()->getTimestampMs());
            try { $s->reply($this->context,$id,[['type'=>'text','text'=>'Hello']],(string)Str::uuid()); self::fail('Ineligible reply queued'); } catch (\RuntimeException) { self::assertTrue(true); }
        }
        self::assertSame(0,DB::table('line_delivery_batches')->count()); self::assertSame(0,DB::table('line_quota_charges')->count());
        self::assertSame(0,DB::table('line_chat_messages')->where('direction','outbound')->count()); Http::assertNothingSent();
    }

    public function test_roles_allow_chat_but_operator_cannot_manage_templates_or_bots(): void
    {
        $this->receive([$this->event()]);
        foreach (\App\Domain\Identity\Enums\V2AdminRole::cases() as $role) {
            DB::table('admins')->where('id',$this->context->adminId)->update(['role'=>$role->value]);
            $c=new \App\Domain\Identity\Contracts\V2AdminAuthorizationContext($this->context->adminId,$this->context->adminPublicId,$role,$this->context->sessionIdHash,$this->context->sessionCorrelationHash,(string)Str::uuid());
            $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class); $s->authorize($c,true); self::assertCount(1,$s->listing($c)['data']);
            $t=app(\App\Domain\Line\Services\V2LineTemplates::class); $t->listing($c);
            if ($role->value==='operator') {
                try { $t->save($c,'categories',null,['name'=>'X','sort_order'=>0,'expected_revision'=>0]); self::fail('Operator wrote template'); }
                catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $e) { self::assertSame(403,$e->status); }
                self::assertFalse(app(\App\Domain\Identity\Services\V2PermissionAuthorizer::class)->allows($role,'line.bot.manage'));
            } else self::assertArrayHasKey('id',$t->save($c,'categories',null,['name'=>'X','sort_order'=>0,'expected_revision'=>0]));
        }
    }

    public function test_media_202_404_mime_mismatch_size_and_deadline_fail_closed_without_later_retry(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        foreach ([[202,'','pending_external'],[404,'','unavailable'],[200,'<html>unsafe</html>','unavailable'],[200,str_repeat('x',8388609),'unavailable']] as $i=>[$status,$body,$expected]) {
            Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
            Http::fake(['*/oauth2/v3/token'=>Http::response(['access_token'=>'synthetic','expires_in'=>900]),'https://api-data.line.me/*'=>Http::response($body,$status,['Content-Type'=>'image/png'])]);
            $e=$this->event((string)(100+$i),'image'); $this->receive([$e,$e]);
            self::assertSame($expected,DB::table('line_chat_messages')->where('line_message_id',(string)(100+$i))->value('media_state'));
            Http::assertSentCount($i===0 ? 2 : 1);
        }
        $file=tempnam(sys_get_temp_dir(),'line-chat-test-');
        try {
            self::assertSame(['state'=>'unavailable'],app(\App\Domain\Line\Services\V2LineBotTransport::class)->chatContent($this->bot,'123',$file,hrtime(true)-1));
            self::assertSame(['state'=>'unavailable'],app(\App\Domain\Line\Services\V2LineBotTransport::class)->chatContent($this->bot,'../123',$file,hrtime(true)+8000000000));
        } finally { unlink($file); }
        self::assertCount(0,\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }

    public function test_video_audio_and_file_use_private_storage_and_write_failure_is_not_saved(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $fixtures=[
            ['video',pack('N',24).'ftypmp42'.pack('N',0).'mp42isom','video/mp4'],
            ['audio','RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',2)."\0\0",'audio/x-wav'],
            ['file','Synthetic file attachment','text/plain'],
        ];
        foreach ($fixtures as $i=>[$type,$body,$mime]) {
            Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
            Http::fake(['*/oauth2/v3/token'=>Http::response(['access_token'=>'synthetic','expires_in'=>900]),'https://api-data.line.me/*'=>Http::response($body,200,['Content-Type'=>$mime])]);
            $e=$this->event((string)(200+$i),$type); if ($type==='file') unset($e['message']['contentProvider']);
            $this->receive([$e]); $m=DB::table('line_chat_messages')->where('line_message_id',(string)(200+$i))->first();
            self::assertSame('stored',$m->media_state,$type); self::assertSame($body,Crypt::decryptString(\Illuminate\Support\Facades\Storage::disk('local')->get($m->media_key)));
        }
        $disk=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false); $disk->shouldReceive('delete')->once()->andReturn(true);
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(['https://api-data.line.me/*'=>Http::response('Synthetic unwritable file',200,['Content-Type'=>'text/plain'])]);
        $e=$this->event('300','file'); unset($e['message']['contentProvider']); $this->receive([$e]);
        $m=DB::table('line_chat_messages')->where('line_message_id','300')->first(); self::assertSame('unavailable',$m->media_state); self::assertNull($m->media_key);
    }

    public function test_maximum_private_file_round_trips_and_one_byte_over_limit_is_unavailable(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $body=str_repeat('x',8388608);
        Http::fake(['*/oauth2/v3/token'=>Http::response(['access_token'=>'synthetic','expires_in'=>900]),'https://api-data.line.me/*'=>Http::response($body,200,['Content-Type'=>'text/plain'])]);
        $e=$this->event('501','file'); unset($e['message']['contentProvider']); $this->receive([$e]);
        $m=DB::table('line_chat_messages')->first(); self::assertSame('stored',$m->media_state); self::assertSame(8388608,$m->media_bytes);
        $id=DB::table('line_conversations')->value('public_id');
        self::assertSame(hash('sha256',$body),hash('sha256',app(\App\Domain\Line\Services\V2LineChatAdmin::class)->attachment($this->context,$id,$m->public_id)['body']));
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(['https://api-data.line.me/*'=>Http::response($body.'x',200,['Content-Type'=>'text/plain'])]);
        $e['webhookEventId']='event-502'; $e['message']['id']='502'; $this->receive([$e]);
        self::assertSame('unavailable',DB::table('line_chat_messages')->where('line_message_id','502')->value('media_state'));
        self::assertNull(DB::table('line_chat_messages')->where('line_message_id','502')->value('media_key'));
    }

    public function test_concurrent_receive_and_reply_wait_for_fence_then_retries_remain_single(): void
    {
        $e=$this->event(); $this->receive([$e]); $id=DB::table('line_conversations')->value('public_id');
        app(\App\Domain\Line\Services\V2LineRecoveredFriendRegistry::class)->record($this->bot->id,1,'U'.str_repeat('a',32),now()->getTimestampMs());
        $q=app(\App\Domain\Line\Services\V2LineDeliveryQuota::class);
        $q->confirmBaseline($this->context,$this->bot->id,1,$q->month(app(\App\Domain\Line\Services\V2LineDeliverySender::class)->clock()),0,10,(string)Str::uuid(),
            '課金対象送信の一元管理・正確な基準値・未確定の外部送信なしを確認しました');
        $key=(string)Str::uuid(); $messages=[['type'=>'text','text'=>'Concurrent synthetic']];
        config(['database.connections.d6_observer'=>config('database.connections.pgsql')]);
        $observer=DB::connection('d6_observer'); $observer->statement("SET lock_timeout = '100ms'");
        try {
            DB::transaction(function () use ($e,$id,$key,$messages): void {
                app(\App\Domain\Line\Services\V2LineMessagingSettingService::class)->setting(true);
                DB::setDefaultConnection('d6_observer');
                try {
                    foreach ([fn()=>$this->receive([$e]),fn()=>app(\App\Domain\Line\Services\V2LineChatAdmin::class)->reply($this->context,$id,$messages,$key)] as $operation) {
                        try { $operation(); self::fail('Concurrent operation escaped fence'); }
                        catch (\Illuminate\Database\QueryException $error) { self::assertSame('55P03',$error->errorInfo[0]); }
                    }
                } finally { DB::setDefaultConnection('pgsql'); }
            });
            $this->receive([$e]); $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class);
            $reply=$s->reply($this->context,$id,$messages,$key); self::assertSame($reply,$s->reply($this->context,$id,$messages,$key));
            self::assertSame(2,DB::table('line_chat_messages')->count()); self::assertSame(1,DB::table('line_delivery_batches')->count()); self::assertSame(1,DB::table('line_quota_charges')->count());
        } finally { DB::purge('d6_observer'); }
        Http::assertNothingSent();
    }

    private function searchableMember(string $key, ?string $display, ?string $full): array
    {
        $event=$this->event((string)(1000+DB::table('line_conversations')->count())); $event['source']['userId']='U'.md5('search-'.$key);
        $this->receive([$event]);
        $conversation=DB::table('line_conversations')->orderByDesc('id')->first();
        $user=$this->rankUser(); $user->forceFill(['display_name'=>$display,'full_name'=>$full])->save();
        $identity=\App\Models\V2\ExternalIdentityAccount::query()->create(['user_id'=>$user->id,'provider'=>'line','issuer'=>'https://access.line.me',
            'subject_hash'=>$conversation->subject_hash,'linked_at'=>now(),'last_authenticated_at'=>now()]);
        return [$conversation,$user,$identity];
    }

    public function test_name_search_matches_line_name_and_null_or_empty_full_name_fallback(): void
    {
        $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class);
        foreach ([['LINE名', '隠れた氏名', 'LINE名'], [null, '氏名だけ', '氏名だけ'], ['', '空のLINE名', '空のLINE名'], ['  ', '空白のLINE名', '空白のLINE名']] as $index=>[$display,$full,$visible]) {
            [$conversation,$user]=$this->searchableMember('fallback-'.$index,$display,$full);
            $rows=$s->listing($this->context,search:$visible)['data'];
            self::assertContains($conversation->public_id,array_column($rows,'id'));
            self::assertSame($visible,$s->detail($this->context,$conversation->public_id)['conversation']['name']);
            self::assertSame($user->public_id,$s->detail($this->context,$conversation->public_id)['conversation']['member_id']);
        }
        self::assertSame([],$s->listing($this->context,search:'隠れた氏名')['data']);
        Http::assertNothingSent();
    }

    public function test_name_search_literal_metacharacters_and_ineligible_links(): void
    {
        $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class);
        foreach (['%', '_', '\\', "x' OR 1=1 --"] as $index=>$literal) {
            [$c]=$this->searchableMember('escape-'.$index,null,'検索'.$literal.'対象');
            self::assertSame([$c->public_id],array_column($s->listing($this->context,search:$literal)['data'],'id'));
        }
        foreach (['active','restricted','suspended','revoked','superseded','closure'] as $state) {
            [$c,$user,$identity]=$this->searchableMember('excluded-'.$state,null,'対象確認'.$state);
            if (in_array($state,['active','restricted','suspended'],true)) $user->forceFill(['state'=>$state])->save();
            if ($state==='revoked') $identity->update(['revoked_at'=>now()]);
            if ($state==='superseded') $identity->forceFill(['superseded_at'=>now()])->save();
            if ($state==='closure') DB::table('account_closures')->insert(['user_id'=>$user->id,'completed_at'=>now(),'closed_by'=>'self',
                'closure_kind'=>'normal','forfeited_points'=>0,'forfeited_prizes'=>0,'request_id'=>(string)Str::uuid()]);
            $rows=$s->listing($this->context,search:'対象確認'.$state)['data'];
            self::assertCount(in_array($state,['active','restricted'],true) ? 1 : 0,$rows);
            if ($rows!==[]) self::assertSame($c->public_id,$rows[0]['id']);
        }
        Http::assertNothingSent();
    }

    public function test_name_search_retains_status_unread_and_pagination(): void
    {
        $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class); $ids=[];
        for ($i=0;$i<32;$i++) {
            [$c]=$this->searchableMember('page-'.$i,$i%2===0 ? '共通検索' : null,'共通検索'); $ids[]=$c->public_id;
        }
        $first=$s->listing($this->context,search:'共通検索'); $second=$s->listing($this->context,page:2,search:'共通検索');
        self::assertCount(30,$first['data']); self::assertSame(2,$first['next_page']); self::assertCount(2,$second['data']); self::assertNull($second['next_page']);
        self::assertEqualsCanonicalizing($ids,array_column([...$first['data'],...$second['data']],'id'));
        $last=DB::table('line_conversations')->where('public_id',$ids[31])->sole();
        $s->status($this->context,$last->public_id,'closed',$last->revision);
        self::assertSame([$last->public_id],array_column($s->listing($this->context,status:'closed',unread:true,search:'共通検索')['data'],'id'));
        $through=DB::table('line_chat_messages')->where('conversation_id',$last->id)->max('id');
        $s->markRead($this->context,$last->public_id,$through);
        self::assertSame([],$s->listing($this->context,status:'closed',unread:true,search:'共通検索')['data']);
        self::assertCount(1,$s->listing($this->context,status:'closed',search:'共通検索')['data']);
        Http::assertNothingSent();
    }

    public function test_suspended_and_revoked_members_are_not_exposed_and_old_bot_cannot_reply(): void
    {
        $this->receive([$this->event()]); $id=DB::table('line_conversations')->value('public_id'); $s=app(\App\Domain\Line\Services\V2LineChatAdmin::class); $user=$this->rankUser();
        $account=\App\Models\V2\ExternalIdentityAccount::query()->create(['user_id'=>$user->id,'provider'=>'line','issuer'=>'https://access.line.me',
            'subject_hash'=>DB::table('line_conversations')->value('subject_hash'),'linked_at'=>now(),'last_authenticated_at'=>now()]);
        self::assertSame($user->public_id,$s->detail($this->context,$id)['conversation']['member_id']);
        DB::table('users')->where('id',$user->id)->update(['state'=>'suspended']);
        self::assertNull($s->detail($this->context,$id)['conversation']['member_id']);
        DB::table('users')->where('id',$user->id)->update(['state'=>'active']); $account->update(['revoked_at'=>now()]);
        self::assertNull($s->detail($this->context,$id)['conversation']['member_id']);
        DB::table('line_bots')->where('id',$this->bot->id)->update(['is_active'=>false]);
        try { $s->reply($this->context,$id,[['type'=>'text','text'=>'Blocked']],(string)Str::uuid()); self::fail('Inactive BOT reply'); } catch (\RuntimeException) { self::assertTrue(true); }
        self::assertSame(0,DB::table('line_delivery_batches')->count());
    }
}

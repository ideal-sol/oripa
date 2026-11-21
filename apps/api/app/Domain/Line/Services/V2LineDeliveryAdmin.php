<?php

namespace App\Domain\Line\Services;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Enums\V2LineDeliveryPurpose as Purpose;
use App\Domain\Line\Enums\V2LineRecipientGroup as Group;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LineDeliveryAdmin
{
    public function authorize(V2AdminAuthorizationContext $context, bool $write): void
    {
        $a=app(V2AdminFreshMfaAuthorizer::class);
        $a->authorizePermission($context,$write ? V2Permission::ManageLineBot : V2Permission::ReadLineBot);
    }

    public function listing(int $cursor): array
    {
        $rows=DB::table('line_deliveries')->when($cursor>0,fn($q)=>$q->where('id','<',$cursor))->orderByDesc('id')->limit(51)->get();
        $more=$rows->count()>50; $page=$rows->take(50);
        return ['data'=>$page->map(function ($r) { $row = $this->row($r); unset($row['messages'], $row['segment'], $row['pacing']); return $row; })->all(),'next_cursor'=>$more ? $page->last()->id : null];
    }

    public function detail(string $id,int $cursor=0): array
    {
        $d=DB::table('line_deliveries')->where('public_id',$id)->firstOrFail();
        $b=DB::table('line_delivery_batches as b')->where('b.delivery_id',$d->id)->where('b.id','>',$cursor)
            ->select('b.id','b.public_id','b.status','b.method','b.attempts','b.reason_code','b.not_before')
            ->selectSub(fn($q)=>$q->from('line_delivery_recipients as r')->whereColumn('r.batch_id','b.id')->selectRaw('count(*)'),'recipient_count')
            ->orderBy('b.id')->limit(101)->get();
        return ['data'=>$this->row($d),'batches'=>$b->take(100)->map(fn($r)=>['id'=>$r->public_id,'status'=>$r->status,'method'=>$r->method,'attempts'=>$r->attempts,
            'reason_code'=>$r->reason_code,'not_before'=>$r->not_before,'recipient_count'=>(int)$r->recipient_count])->all(),'next_batch_cursor'=>$b->count()>100 ? $b[99]->id : null,
            'estimated_dispatch_end'=>DB::table('line_delivery_batches')->where('delivery_id',$d->id)->max('not_before')];
    }

    public function save(V2AdminAuthorizationContext $context,?string $publicId,array $input): array
    {
        $this->keys($input,['title','category','recipient_group','segment','messages','pacing','expected_revision','mode','scheduled_at','request_key']);
        $this->integer($input['expected_revision']);
        if (! is_string($input['request_key']) || ! Str::isUuid($input['request_key']) || ! is_string($input['title']) || ! is_array($input['segment']) || ! is_array($input['messages'])) $this->invalid();
        $d=$publicId===null ? null : DB::table('line_deliveries')->where('public_id',$publicId)->firstOrFail();
        if ($d===null && $input['expected_revision']!==0) $this->invalid();
        $when=match($input['mode']) {'draft'=>null,'now'=>CarbonImmutable::now(),'scheduled'=>$this->date($input['scheduled_at']),default=>$this->invalid()};
        if ($publicId === null && $input['mode'] === 'now') {
            $original = DB::table('line_deliveries')->where('create_request_key',$input['request_key'])->where('created_by_admin_id',$context->adminId)->first();
            if ($original !== null && $original->scheduled_at !== null) $when = CarbonImmutable::parse($original->scheduled_at);
        }
        if ($input['mode']!=='scheduled' && $input['scheduled_at']!==null) $this->invalid();
        if ($input['mode']==='scheduled' && $when->lte(CarbonImmutable::now())) $this->invalid();
        $pacing=app(V2LineDeliveryService::class)->pacing(json_encode($input['pacing'],JSON_THROW_ON_ERROR));
        app(V2LineDeliveryMessages::class)->validate($input['messages']);
        foreach ($input['messages'] as $m) if ($m['type']!=='text') app(V2LineDeliveryMedia::class)->assertVerified($m);
        if ($when!==null) {
            $bot=$this->bot(); $q=app(V2LineDeliveryQuota::class); $q->prepareScheduledMonth($bot, $when);
            if ($this->quota()['halted']) throw new \RuntimeException('LINE quota unavailable.');
            if (! DB::table('line_quota_periods')->where('line_bot_id',$bot->id)->where('month',$q->month($when))->exists()) throw new \RuntimeException('LINE quota baseline unavailable.');
        }
        $id=app(V2LineDeliveryService::class)->save($context,$d?->id,$input['expected_revision'],Purpose::from($input['category']),Group::from($input['recipient_group']),
            $input['messages'],$when,$pacing,$input['title'],$input['segment'],$input['request_key']);
        return $this->detail(DB::table('line_deliveries')->where('id',$id)->value('public_id'));
    }

    public function action(V2AdminAuthorizationContext $context,string $id,string $operation,array $input): array
    {
        $this->keys($input,['expected_revision','request_key']); $this->integer($input['expected_revision']);
        if (! is_string($input['request_key']) || ! Str::isUuid($input['request_key'])) $this->invalid();
        $d=DB::table('line_deliveries')->where('public_id',$id)->firstOrFail();
        if ($d->revision!==$input['expected_revision']) throw new \RuntimeException('LINE delivery revision conflict.');
        $s=app(V2LineDeliveryService::class);
        if ($operation==='cancel') { $s->cancel($context,$d->id,$d->revision); return $this->detail($id); }
        if ($operation!=='duplicate') $this->invalid();
        $copy=$s->save($context,null,0,Purpose::from($d->category),Group::from($d->recipient_group),json_decode(Crypt::decryptString($d->messages_ciphertext),true,32,JSON_THROW_ON_ERROR),
            null,$s->pacing($d->pacing),Crypt::decryptString($d->title_ciphertext),json_decode($d->segment,true,32,JSON_THROW_ON_ERROR),$input['request_key']);
        return $this->detail(DB::table('line_deliveries')->where('id',$copy)->value('public_id'));
    }

    public function preview(array $input): array
    {
        $this->keys($input,['category','recipient_group','segment','messages','pacing']);
        if (! is_array($input['segment']) || ! is_array($input['messages'])) $this->invalid();
        $p=app(V2LineDeliveryService::class)->pacing(json_encode($input['pacing'],JSON_THROW_ON_ERROR));
        $push=app(V2LineDeliveryMessages::class)->personalized($input['messages']);
        $result=app(V2LineDeliveryService::class)->estimate(Group::from($input['recipient_group']),Purpose::from($input['category']),$input['segment']);
        $batches=$p->batchCount($result['recipient_count'],$push);
        return [...$result,'method'=>$push ? 'push' : 'multicast','duration_seconds'=>$batches===0 ? 0 : $p->offsetSeconds($push ? 'push' : 'multicast',$batches-1)];
    }

    public function defaults(): array
    {
        $r=DB::table('line_delivery_settings')->where('id',1)->firstOrFail();
        return ['revision'=>$r->revision,'pacing'=>['push_every'=>$r->push_every,'push_wait_seconds'=>$r->push_wait_seconds,'multicast_wait_seconds'=>$r->multicast_wait_seconds]];
    }

    public function quota(bool $refresh = false): array
    {
        $b=$this->bot(); $q=app(V2LineDeliveryQuota::class); $providerError=false;
        if ($refresh) {
            try { $q->providerRemaining($b); } catch (\Throwable) { $providerError=true; }
        }
        $a=DB::table('line_quota_accounts')->where('line_bot_id',$b->id)->first(); $periods=[];
        $now=app(V2LineDeliverySender::class)->clock();
        foreach ([$q->month($now),$q->nextMonth($now)] as $month) {
            $p=DB::table('line_quota_periods')->where('line_bot_id',$b->id)->where('month',$month)->first();
            $counts=DB::table('line_quota_charges')->where('line_bot_id',$b->id)->where('month',$month)->selectRaw('state,SUM(units) as units')->groupBy('state')->pluck('units','state');
            $periods[]=['month'=>$month,'configured'=>$p!==null,'baseline_origin'=>$p?->baseline_origin,'opening_used'=>$p?->opening_used??0,'approved_limit'=>$p?->approved_limit??0,'effective_limit'=>$p?->effective_limit??0,
                'reserved'=>(int)($counts['reserved']??0),'unknown'=>$q->unknown($b->id,$month),'accepted'=>(int)($counts['accepted']??0),'remaining'=>$q->available($b->id,$month),'evidence_id'=>$p?->evidence_id,'verified_at'=>$p?->verified_at,'baseline_cut_at'=>$p?->baseline_cut_at];
        }
        $halted=$a!==null && ($a->halted || $a->authentication_revision!==$b->authentication_revision || $a->usable_from>$q->month($now));
        $state=$halted ? 'halted' : ($providerError ? 'provider_error' : (! $periods[0]['configured'] ? 'initialization_pending' :
            ($periods[0]['remaining']===0 ? 'limit_reached' : ($periods[0]['baseline_origin']==='system_initial' ? 'initialized' : 'managed'))));
        return ['bot_id'=>$b->public_id,'authentication_revision'=>$b->authentication_revision,'halted'=>$halted,'halt_reason'=>$a?->halt_reason,
            'automation_state'=>$state,
            'last_checked_at'=>$a?->last_checked_at === null ? null : CarbonImmutable::parse($a->last_checked_at)->toIso8601String(),'periods'=>$periods];
    }

    public function quotaWrite(V2AdminAuthorizationContext $c,string $operation,array $input): array
    {
        return DB::transaction(function () use ($c, $operation, $input): array {
        app(V2LineMessagingSettingService::class)->setting(true);
        $keys=$operation==='baseline' ? ['bot_id','authentication_revision','month','opening_used','approved_limit','evidence_id','verified_at','confirmation'] : ['bot_id','authentication_revision','evidence_id'];
        $this->keys($input,$keys); $b=$this->bot();
        if ($input['bot_id']!==$b->public_id || $input['authentication_revision']!==$b->authentication_revision) throw new \RuntimeException('LINE BOT revision conflict.');
        $q=app(V2LineDeliveryQuota::class);
        if ($operation==='baseline') {
            $this->integer($input['opening_used']); $this->integer($input['approved_limit']);
            if ($this->date($input['verified_at'])->gt(CarbonImmutable::now())) $this->invalid();
            $q->confirmBaseline($c,$b->id,$b->authentication_revision,$input['month'],$input['opening_used'],$input['approved_limit'],$input['evidence_id'],$input['confirmation'],$this->date($input['verified_at']));
        } else $q->halt($c,$b->id,$input['evidence_id']);
        return $this->quota();
        }, 1);
    }

    private function row(object $r): array
    {
        $empty=array_fill_keys(['accepted','failed','unknown','skipped','pending','sending','retrying'],0); $batches=$empty; $recipients=$empty;
        foreach(DB::table('line_delivery_batches')->where('delivery_id',$r->id)->selectRaw('status,count(*) as count')->groupBy('status')->get() as $b) $batches[$b->status]=(int)$b->count;
        foreach(DB::table('line_delivery_recipients as r')->join('line_delivery_batches as b','b.id','=','r.batch_id')->where('b.delivery_id',$r->id)
            ->selectRaw("CASE WHEN r.status='skipped' THEN 'skipped' ELSE b.status END as state,count(*) as count")->groupBy('state')->get() as $b) $recipients[$b->state]=(int)$b->count;
        return ['id'=>$r->public_id,'revision'=>$r->revision,'title'=>Crypt::decryptString($r->title_ciphertext),'category'=>$r->category,'recipient_group'=>$r->recipient_group,
            'segment'=>(object)json_decode($r->segment,true,32,JSON_THROW_ON_ERROR),'messages'=>json_decode(Crypt::decryptString($r->messages_ciphertext),true,32,JSON_THROW_ON_ERROR),'pacing'=>json_decode($r->pacing,true,32,JSON_THROW_ON_ERROR),
            'status'=>$r->status,'scheduled_at'=>$r->scheduled_at,'created_by'=>$r->created_by_admin_id===null ? null : DB::table('admins')->where('id',$r->created_by_admin_id)->value('public_id'),
            'created_at'=>$r->created_at,'recipient_count'=>$r->recipient_count,'planned_count'=>$r->planned_count,'bot_id'=>$r->line_bot_id===null ? null : DB::table('line_bots')->where('id',$r->line_bot_id)->value('public_id'),
            'authentication_revision'=>$r->authentication_revision,'batch_counts'=>$batches,'recipient_counts'=>$recipients];
    }
    private function bot(): object { return DB::table('line_bots')->where('is_active',true)->where('connection_status','succeeded')->firstOrFail(); }
    public function keys(array $input,array $keys): void { if (array_diff(array_keys($input),$keys) || array_diff($keys,array_keys($input))) $this->invalid(); }
    public function integer(mixed $v): void { if (! is_int($v) || $v<0 || $v>9007199254740991) $this->invalid(); }
    private function date(mixed $v): CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/',$v)) $this->invalid();
        $parts = date_parse($v);
        if ($parts['error_count'] !== 0 || $parts['warning_count'] !== 0) $this->invalid();
        try { return CarbonImmutable::parse($v)->utc(); } catch (\Throwable) { $this->invalid(); }
    }
    private function invalid(): never { throw new \InvalidArgumentException('LINE_DELIVERY_INPUT_INVALID'); }
}

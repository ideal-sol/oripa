<?php

namespace App\Domain\Line\Services;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext as Context;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Enums\V2LineDeliveryPurpose;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class V2LineChatAdmin
{
    public function authorize(Context $context, bool $reply = false): void
    {
        app(V2AdminFreshMfaAuthorizer::class)->authorizePermission($context, $reply ? V2Permission::ReplyLineChat : V2Permission::ReadLineChat);
    }

    public function audit(Context $c, string $action, string $id): void
    {
        app(\App\Domain\Audit\V2\Services\V2AuditLogService::class)->record($action, ['request_id'=>$c->requestId,
            'actor_type'=>'admin','actor_public_id'=>$c->adminPublicId,'actor_role'=>$c->role->value,'auth_realm'=>'admin',
            'target_type'=>'line_chat','target_public_id'=>$id,'outcome'=>'success']);
    }

    public function listing(Context $context, int $page = 1, string $status = '', bool $unread = false, string $search = ''): array
    {
        $this->authorize($context);
        if ($page < 1 || $page > 1000 || mb_strlen($search)>80 || ! in_array($status, ['', 'open', 'closed'], true)) throw new \InvalidArgumentException('LINE_CHAT_INVALID');
        $q = DB::table('line_conversations');
        if ($search!=='') $q->whereExists(function ($query) use ($search): void {
            $query->selectRaw('1')->from('external_identity_accounts as e')->join('users as u','u.id','=','e.user_id')
                ->whereColumn('e.subject_hash','line_conversations.subject_hash')->where('e.provider','line')->where('e.issuer','https://access.line.me')
                ->whereNull('e.revoked_at')->whereNull('e.superseded_at')->whereIn('u.state',['active','restricted'])
                ->whereNotExists(fn($x)=>$x->selectRaw('1')->from('account_closures as ac')->whereColumn('ac.user_id','u.id'))
                ->where('u.display_name','ilike','%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%');
        });
        if ($status !== '') $q->where('status', $status);
        if ($unread) $q->whereExists(fn ($q) => $q->selectRaw('1')->from('line_chat_messages as m')->whereColumn('m.conversation_id','line_conversations.id')->where('m.direction','inbound')->whereNull('m.read_at')->where('m.unsent',false));
        $rows = $q->orderByDesc('last_message_at')->orderByDesc('id')->offset(($page-1)*30)->limit(31)->get();
        $data = [];
        foreach ($rows->take(30) as $row) $data[] = $this->conversation($row);
        $this->audit($context, 'line.chat.listed', $context->adminPublicId);
        return ['data'=>$data,'next_page'=>$rows->count()>30 ? $page+1 : null];
    }

    public function detail(Context $context, string $id, int $before = 0): array
    {
        $this->authorize($context);
        if ($before < 0) throw new \InvalidArgumentException('LINE_CHAT_INVALID');
        $c = DB::table('line_conversations')->where('public_id',$id)->firstOrFail();
        $q = DB::table('line_chat_messages')->where('conversation_id',$c->id);
        if ($before > 0) {
            $anchor=DB::table('line_chat_messages')->where('conversation_id',$c->id)->where('id',$before)->firstOrFail();
            $q->where(fn($p)=>$p->where('received_at','<',$anchor->received_at)->orWhere(fn($same)=>$same->where('received_at',$anchor->received_at)->where('id','<',$before)));
        }
        $rows = $q->orderByDesc('received_at')->orderByDesc('id')->limit(51)->get();
        $messages = [];
        foreach ($rows->take(50)->reverse() as $m) {
            $state = $m->media_state === 'fetching' ? 'unavailable' : $m->media_state;
            $messages[] = ['id'=>$m->public_id,'sequence'=>$m->id,'direction'=>$m->direction,'type'=>$m->type,
                'unsent'=>$m->unsent,'body'=>$m->unsent ? null : json_decode(Crypt::decryptString($m->body_ciphertext),true,32,JSON_THROW_ON_ERROR),
                'media_state'=>$state,'has_attachment'=>! $m->unsent && $state==='stored','received_at'=>$m->received_at,
                'batch_status'=>$m->batch_id === null ? null : DB::table('line_delivery_batches')->where('id',$m->batch_id)->value('status')];
        }
        $this->audit($context,'line.chat.viewed',$id);
        return ['conversation'=>$this->conversation($c),'messages'=>$messages,'next_before'=>$rows->count()>50 ? $rows[49]->id : null];
    }

    public function markRead(Context $context, string $id, int $through): void
    {
        $this->authorize($context);
        if ($through < 1) throw new \InvalidArgumentException('LINE_CHAT_INVALID');
        DB::transaction(function () use ($context,$id,$through): void {
            $c=DB::table('line_conversations')->where('public_id',$id)->lockForUpdate()->firstOrFail();
            if (! DB::table('line_chat_messages')->where('conversation_id',$c->id)->where('id',$through)->exists()) throw new \InvalidArgumentException('LINE_CHAT_INVALID');
            DB::table('line_chat_messages')->where('conversation_id',$c->id)->where('direction','inbound')->where('id','<=',$through)->whereNull('read_at')->update(['read_at'=>\App\Support\V2DatabaseTimestamp::format(now())]);
            $this->audit($context,'line.chat.read',$id);
        },1);
    }

    public function status(Context $context, string $id, string $status, int $revision): void
    {
        $this->authorize($context,true);
        if (! in_array($status,['open','closed'],true)) throw new \InvalidArgumentException('LINE_CHAT_INVALID');
        DB::transaction(function () use ($context,$id,$status,$revision): void {
            if (DB::table('line_conversations')->where('public_id',$id)->where('revision',$revision)->update(['status'=>$status,'revision'=>$revision+1])!==1) throw new \RuntimeException('LINE_CHAT_CONFLICT');
            $this->audit($context,'line.chat.status.updated',$id);
        },1);
    }

    public function reply(Context $context, string $id, #[\SensitiveParameter] array $messages, string $key): string
    {
        $this->authorize($context,true);
        if (! Str::isUuid($key)) throw new \InvalidArgumentException('LINE_CHAT_INVALID');
        app(V2LineDeliveryMessages::class)->validate($messages);
        $hash=hash('sha256',json_encode([$id,$context->adminId,$messages],JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($context,$id,$messages,$key,$hash): string {
            app(V2LineMessagingSettingService::class)->setting(true);
            $existing=DB::table('line_chat_messages')->where('request_key',$key)->first();
            if ($existing!==null) {
                if (! hash_equals($existing->request_hash,$hash)) throw new \RuntimeException('LINE_CHAT_CONFLICT');
                return $existing->public_id;
            }
            // D3 keys are global across source types. Never adopt another operation's batch.
            if (DB::table('line_delivery_batches')->where('source_key',$key)->exists()) throw new \RuntimeException('LINE_CHAT_CONFLICT');
            $c=DB::table('line_conversations')->where('public_id',$id)->lockForUpdate()->firstOrFail();
            $ref=app(V2LineRecipientResolver::class)->chatRecipient($c->line_bot_id,$c->subject_hash);
            if ($ref===null) throw new \RuntimeException('LINE_CHAT_RECIPIENT_UNAVAILABLE');
            foreach ($messages as $m) if ($m['type']!=='text') app(V2LineDeliveryMedia::class)->assertVerified($m);
            $batch=app(V2LineDeliveryService::class)->enqueuePush($ref,V2LineDeliveryPurpose::Transactional,$messages,'chat',$key);
            $mid=(string)Str::uuid();
            DB::table('line_chat_messages')->insert(['public_id'=>$mid,'conversation_id'=>$c->id,'line_bot_id'=>$c->line_bot_id,
                'authentication_revision'=>$ref->authenticationRevision,'direction'=>'outbound','type'=>'messages','request_key'=>$key,'request_hash'=>$hash,
                'body_ciphertext'=>Crypt::encryptString(json_encode($messages,JSON_THROW_ON_ERROR)),'sent_by'=>$context->adminId,'batch_id'=>$batch,'received_at'=>\App\Support\V2DatabaseTimestamp::format(now())]);
            DB::table('line_conversations')->where('id',$c->id)->where('last_message_at','<',\App\Support\V2DatabaseTimestamp::format(now()))->update(['last_message_at'=>\App\Support\V2DatabaseTimestamp::format(now())]);
            $this->audit($context,'line.chat.reply.queued',$mid);
            return $mid;
        },1);
    }

    public function attachment(Context $context, string $conversationId, string $messageId): array
    {
        $this->authorize($context);
        return DB::transaction(function () use ($context,$conversationId,$messageId): array {
            // Serialize read against unsend. The body is never returned after the cancellation commits.
            app(V2LineMessagingSettingService::class)->setting(true);
            $c=DB::table('line_conversations')->where('public_id',$conversationId)->firstOrFail();
            $m=DB::table('line_chat_messages')->where('conversation_id',$c->id)->where('public_id',$messageId)->where('unsent',false)->where('media_state','stored')->firstOrFail();
            $bot=DB::table('line_bots')->where('id',$c->line_bot_id)->firstOrFail();
            $prefix='line-chat/'.$bot->public_id.'/'.$c->id.'/'.$m->public_id.'/';
            if (! is_string($m->media_key) || ! str_starts_with($m->media_key,$prefix) || ! preg_match('/\A[0-9a-f-]{36}\.bin\z/',substr($m->media_key,strlen($prefix)))) throw new \RuntimeException('LINE_CHAT_MEDIA_UNAVAILABLE');
            $disk=Storage::disk('local');
            if (! $disk->exists($m->media_key) || $disk->size($m->media_key)>40000000) throw new \RuntimeException('LINE_CHAT_MEDIA_UNAVAILABLE');
            $body=Crypt::decryptString($disk->get($m->media_key));
            if (strlen($body)!==$m->media_bytes) throw new \RuntimeException('LINE_CHAT_MEDIA_UNAVAILABLE');
            $this->audit($context,'line.chat.attachment.viewed',$messageId);
            return ['body'=>$body,'mime'=>$m->media_mime];
        },1);
    }

    private function conversation(object $c): array
    {
        $members=DB::table('external_identity_accounts as e')->join('users as u','u.id','=','e.user_id')
            ->where('e.subject_hash',$c->subject_hash)->where('e.provider','line')->where('e.issuer','https://access.line.me')
            ->whereNull('e.revoked_at')->whereNull('e.superseded_at')->whereIn('u.state',['active','restricted'])
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('account_closures as x')->whereColumn('x.user_id','u.id'))
            ->select('u.id','u.public_id','u.display_name')->limit(2)->get();
        $u=$members->count()===1 ? $members->first() : null;
        DB::table('line_conversations')->where('id',$c->id)->update(['user_id'=>$u?->id]);
        $last=DB::table('line_chat_messages')->where('conversation_id',$c->id)->orderByDesc('received_at')->orderByDesc('id')->first();
        $unread=DB::table('line_chat_messages')->where('conversation_id',$c->id)->where('direction','inbound')->whereNull('read_at')->where('unsent',false)->count();
        $wallet=$u===null ? null : DB::table('wallets')->where('user_id',$u->id)->first();
        $rank=$u===null ? null : app(\App\Domain\MemberRank\Services\V2MemberRankResolver::class)->project($u->id);
        return ['id'=>$c->public_id,'bot_id'=>DB::table('line_bots')->where('id',$c->line_bot_id)->value('public_id'),
            'member_id'=>$u?->public_id,'name'=>$u?->display_name ?? '未登録の友だち','rank_level'=>$rank!==null && $rank['enabled'] ? $rank['level'] : null,
            'points'=>$u===null ? null : (int)($wallet?->paid_balance ?? 0)+(int)($wallet?->free_balance ?? 0),
            'status'=>$c->status,'revision'=>$c->revision,'unread_count'=>$unread,'last_message_at'=>$c->last_message_at,
            'summary'=>$last===null ? '' : ($last->unsent ? '取消済み' : ($last->type==='text' ? mb_substr(json_decode(Crypt::decryptString($last->body_ciphertext),true)['text'],0,80) : $last->type))];
    }
}

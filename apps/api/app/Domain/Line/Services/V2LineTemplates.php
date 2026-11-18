<?php

namespace App\Domain\Line\Services;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext as Context;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LineTemplates
{
    public function listing(Context $c, ?string $botId = null, ?string $category = null, int $page = 1): array
    {
        $this->authorize($c,false);
        if ($page<1 || $page>1000) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
        $bot=$this->bot($botId);
        $q=DB::table('line_message_templates')->where('line_bot_id',$bot->id);
        if ($category!==null) $q->where('category_id',$category);
        $rows=$q->orderBy('id')->offset(($page-1)*30)->limit(31)->get();
        $data=[];
        foreach ($rows->take(30) as $r) $data[]=$this->view($r);
        return ['bot_id'=>$bot->public_id,'data'=>$data,'next_page'=>$rows->count()>30 ? $page+1 : null,
            'categories'=>DB::table('line_template_categories')->where('line_bot_id',$bot->id)->orderBy('sort_order')->orderBy('id')->limit(200)->get(['id','name','sort_order','revision'])->all(),
            'bots'=>DB::table('line_bots')->orderByDesc('is_active')->orderBy('id')->limit(100)->get(['public_id as id','name','is_active'])->all()];
    }

    public function save(Context $c, string $kind, ?string $id, #[\SensitiveParameter] array $input, bool $delete = false): array
    {
        $this->authorize($c,true);
        if (! in_array($kind,['templates','categories'],true)) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
        $allowed=$kind==='templates' ? ['bot_id','expected_revision','name','category_id','messages'] : ['bot_id','expected_revision','name','sort_order'];
        if (array_diff(array_keys($input),$allowed) || ! is_int($input['expected_revision'] ?? null)) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
        if (! $delete && (! is_string($input['name'] ?? null) || trim($input['name'])==='' || mb_strlen($input['name'])>100)) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
        return DB::transaction(function () use ($c,$kind,$id,$input,$delete): array {
            app(V2LineMessagingSettingService::class)->setting(true);
            $bot=$this->bot($input['bot_id'] ?? null);
            $table=$kind==='templates' ? 'line_message_templates' : 'line_template_categories';
            $row=$id===null ? null : DB::table($table)->where('id',$id)->where('line_bot_id',$bot->id)->lockForUpdate()->firstOrFail();
            if (($row?->revision ?? 0)!==$input['expected_revision']) throw new \RuntimeException('LINE_TEMPLATE_CONFLICT');
            if ($delete) {
                if ($row===null || ($kind==='categories' && DB::table('line_message_templates')->where('category_id',$id)->exists())) throw new \RuntimeException('LINE_TEMPLATE_CATEGORY_IN_USE');
                DB::table($table)->where('id',$id)->delete();
                app(V2LineChatAdmin::class)->audit($c,'line.template.deleted',$id);
                return ['id'=>$id];
            }
            $values=['name'=>trim($input['name']),'revision'=>($row?->revision ?? 0)+1];
            if ($kind==='templates') {
                if (! $bot->is_active || $bot->connection_status!=='succeeded' || ! is_array($input['messages'] ?? null)) throw new \InvalidArgumentException('LINE_TEMPLATE_BOT_UNAVAILABLE');
                app(V2LineDeliveryMessages::class)->validate($input['messages']);
                foreach ($input['messages'] as $message) if ($message['type']!=='text') app(V2LineDeliveryMedia::class)->assertVerified($message);
                $category=$input['category_id'] ?? null;
                if ($category!==null && ! DB::table('line_template_categories')->where('id',$category)->where('line_bot_id',$bot->id)->exists()) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
                $values+=['category_id'=>$category,'messages_ciphertext'=>Crypt::encryptString(json_encode($input['messages'],JSON_THROW_ON_ERROR)),'updated_by'=>$c->adminId,'updated_at'=>\App\Support\V2DatabaseTimestamp::format(now())];
            } else {
                if (! is_int($input['sort_order'] ?? null) || $input['sort_order']<0 || $input['sort_order']>10000) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
                if ($row===null && DB::table($table)->where('line_bot_id',$bot->id)->count()>=200) throw new \InvalidArgumentException('LINE_TEMPLATE_CATEGORY_LIMIT');
                $values['sort_order']=$input['sort_order'];
            }
            if ($row===null) {
                $id=(string)Str::uuid();
                if ($kind==='templates') $values['created_by']=$c->adminId;
                DB::table($table)->insert([...$values,'id'=>$id,'line_bot_id'=>$bot->id]);
            } else DB::table($table)->where('id',$id)->update($values);
            app(V2LineChatAdmin::class)->audit($c,'line.template.saved',$id);
            return ['id'=>$id,'revision'=>$values['revision']];
        },1);
    }

    /** Reply-scoped read-only selection; Owner-authorized upload path remains unchanged. */
    public function media(Context $c, int $page = 1): array
    {
        app(V2LineChatAdmin::class)->authorize($c,true);
        if ($page<1 || $page>1000) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
        $bot=$this->bot(null);
        $rows=DB::table('line_media_assets')->where('line_bot_id',$bot->id)->where('authentication_revision',$bot->authentication_revision)->orderBy('id')->offset(($page-1)*30)->limit(31)->get();
        $data=[];
        foreach ($rows->take(30) as $r) $data[]=array_intersect_key(json_decode($r->manifest,true,32,JSON_THROW_ON_ERROR),array_flip(['id','kind','original_url','preview_url','width','height']));
        app(V2LineChatAdmin::class)->audit($c,'line.chat.media.listed',$bot->public_id);
        return ['data'=>$data,'next_page'=>$rows->count()>30 ? $page+1 : null];
    }

    private function view(object $r): array
    {
        return ['id'=>$r->id,'name'=>$r->name,'category_id'=>$r->category_id,'revision'=>$r->revision,
            'messages'=>json_decode(Crypt::decryptString($r->messages_ciphertext),true,32,JSON_THROW_ON_ERROR)];
    }
    private function bot(?string $id): object
    {
        if ($id!==null && ! Str::isUuid($id)) throw new \InvalidArgumentException('LINE_TEMPLATE_INVALID');
        $q=DB::table('line_bots');
        return ($id===null ? $q->where('is_active',true) : $q->where('public_id',$id))->firstOrFail();
    }
    private function authorize(Context $c, bool $write): void
    {
        app(V2AdminFreshMfaAuthorizer::class)->authorizePermission($c,$write ? V2Permission::ManageLineTemplate : V2Permission::ReadLineTemplate);
    }
}

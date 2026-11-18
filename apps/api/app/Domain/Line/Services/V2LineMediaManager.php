<?php

namespace App\Domain\Line\Services;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LineMediaManager
{
    public function intent(V2AdminAuthorizationContext $context, array $input): array
    {
        $this->authorize($context); $this->origin();
        if (array_diff(array_keys($input), ['kind', 'original', 'preview']) || ! in_array($input['kind'] ?? null, ['image','video','imagemap','carousel'], true)) $this->invalid();
        $kind = $input['kind'];
        if (($kind === 'video') !== isset($input['preview'])) $this->invalid();
        $id = (string) Str::uuid(); $files = [];
        foreach (['original','preview'] as $slot) {
            if (! isset($input[$slot]) && $slot === 'preview') continue;
            $f = $input[$slot] ?? null; $video = $kind === 'video' && $slot === 'original';
            if (! is_array($f) || array_diff(array_keys($f), ['mime','bytes','sha256'])
                || ! in_array($f['mime'] ?? null, $video ? ['video/mp4'] : ['image/jpeg','image/png'], true)
                || ! is_int($f['bytes'] ?? null) || $f['bytes'] < 1 || $f['bytes'] > ($video ? V2LineMediaInspector::VIDEO_BYTES : ($slot === 'preview' ? V2LineMediaInspector::PREVIEW_BYTES : V2LineMediaInspector::IMAGE_BYTES))
                || ! is_string($f['sha256'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $f['sha256'])) $this->invalid();
            $files[$slot] = [...$f, 'key' => 'line-upload-private/'.$id.'/'.$slot];
        }
        $expires = now()->addMinutes(5);
        DB::transaction(function () use ($context, $id, $kind, $files, $expires): void {
            app(V2LineMessagingSettingService::class)->setting(true); $bot = $this->bot();
            DB::table('line_media_intents')->insert(['id'=>$id,'line_bot_id'=>$bot->id,'authentication_revision'=>$bot->authentication_revision,
                'created_by_admin_id'=>$context->adminId,'kind'=>$kind,'files'=>json_encode($files, JSON_THROW_ON_ERROR),'expires_at'=>$expires]);
            $this->audit($context, 'line.media.intent.created', $id);
        }, 1);
        $uploads = [];
        foreach ($files as $slot => $file) $uploads[] = ['slot'=>$slot,...app(V2LineMediaStorage::class)->upload($file)];
        return ['id'=>$id,'expires_at'=>$expires->toIso8601String(),'uploads'=>$uploads];
    }

    public function finalize(V2AdminAuthorizationContext $context, string $id): array
    {
        $this->authorize($context); $this->origin();
        $intent = DB::table('line_media_intents')->where('id',$id)->where('created_by_admin_id',$context->adminId)->firstOrFail();
        $this->usable($intent); $files = json_decode($intent->files,true,32,JSON_THROW_ON_ERROR);
        $directory = sys_get_temp_dir().'/line-media-'.Str::uuid();
        if (! mkdir($directory,0700)) throw new \RuntimeException('LINE_MEDIA_UNAVAILABLE');
        $inspect = app(V2LineMediaInspector::class); $storage = app(V2LineMediaStorage::class);
        try {
            foreach ($files as $slot=>$file) $storage->download($file,$directory.'/'.$slot);
            $original = $directory.'/original'; $preview = $directory.'/preview';
            $info = $intent->kind === 'video' ? $inspect->video($original,$preview) : $inspect->image($original);
            if ($info['mime'] !== $files['original']['mime']) $this->invalid();
            if ($intent->kind === 'imagemap' && (int) round($info['height'] * 1040 / $info['width']) > 10000) $this->invalid();
            if ($intent->kind === 'video' && $inspect->image($preview,V2LineMediaInspector::PREVIEW_BYTES)['mime'] !== $files['preview']['mime']) $this->invalid();
            $prefix = 'admin-assets/line-delivery/'.$id; $objects = [];
            if ($intent->kind === 'imagemap') {
                foreach ([240,300,460,700,1040] as $width) {
                    $path = $directory.'/'.$width; $meta = $inspect->resize($original,$path,$width);
                    $objects[] = ['key'=>$prefix.'/'.$width,...$meta]; $storage->publish($prefix.'/'.$width,$path,'image/jpeg');
                    if ($width === 1040) $info = $meta;
                }
                $originalUrl = $this->origin().'/line-delivery/'.$id; $previewUrl = $originalUrl.'/240';
            } elseif ($intent->kind === 'carousel') {
                $path=$directory.'/square'; $info=$inspect->resize($original,$path,1024,true);
                $objects[]=['key'=>$prefix.'/original',...$info]; $storage->publish($prefix.'/original',$path,'image/jpeg');
                $originalUrl=$previewUrl=$this->origin().'/line-delivery/'.$id.'/original';
            } else {
                if ($intent->kind === 'image') $inspect->resize($original,$preview,min(1024,$info['width']));
                $p=$inspect->image($preview,V2LineMediaInspector::PREVIEW_BYTES);
                foreach (['original'=>[$original,$info],'preview'=>[$preview,$p]] as $slot=>[$path,$meta]) {
                    $objects[]=['key'=>$prefix.'/'.$slot,...$meta]; $storage->publish($prefix.'/'.$slot,$path,$meta['mime']);
                }
                $originalUrl=$this->origin().'/line-delivery/'.$id.'/original'; $previewUrl=$this->origin().'/line-delivery/'.$id.'/preview';
            }
            $result=['id'=>$id,'kind'=>$intent->kind,'original_url'=>$originalUrl,'preview_url'=>$previewUrl,'width'=>$info['width'],'height'=>$info['height']];
            DB::transaction(function () use ($context,$id,$result,$objects): void {
                app(V2LineMessagingSettingService::class)->setting(true);
                $i=DB::table('line_media_intents')->where('id',$id)->lockForUpdate()->firstOrFail(); $this->usable($i);
                DB::table('line_media_assets')->insert(['id'=>$id,'line_bot_id'=>$i->line_bot_id,'authentication_revision'=>$i->authentication_revision,'kind'=>$i->kind,'manifest'=>json_encode([...$result,'objects'=>$objects],JSON_THROW_ON_ERROR)]);
                DB::table('line_media_intents')->where('id',$id)->update(['finalized_at'=>now()]); $this->audit($context,'line.media.verified',$id);
            },1);
            return $result;
        } finally {
            foreach (glob($directory.'/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function origin(): string { return app(\App\Domain\Catalog\Services\V2AssetPublicPathResolver::class)->baseUrl(); }

    private function bot(): object { return DB::table('line_bots')->where('is_active',true)->where('connection_status','succeeded')->firstOrFail(); }
    private function usable(object $i): void
    {
        $bot=$this->bot();
        if ($i->finalized_at !== null || \Carbon\CarbonImmutable::parse($i->expires_at)->lte(now()) || $i->line_bot_id !== $bot->id || $i->authentication_revision !== $bot->authentication_revision) $this->invalid();
    }
    private function authorize(V2AdminAuthorizationContext $context): void
    {
        $a=app(V2AdminFreshMfaAuthorizer::class); $a->authorizePermission($context,V2Permission::ManageLineBot);
    }
    private function audit(V2AdminAuthorizationContext $c,string $action,string $id): void
    {
        app(\App\Domain\Audit\V2\Services\V2AuditLogService::class)->record($action,['request_id'=>$c->requestId,'actor_type'=>'admin','actor_public_id'=>$c->adminPublicId,'actor_role'=>$c->role->value,'auth_realm'=>'admin','target_type'=>'line_media','target_public_id'=>$id,'outcome'=>'success']);
    }
    private function invalid(): never { throw new \InvalidArgumentException('LINE_MEDIA_INVALID'); }
}

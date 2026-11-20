<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Services\V2LineDeliveryAdmin;
use App\Domain\Line\Services\V2LineDeliveryService;
use App\Domain\Line\Services\V2LineMediaManager;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2AdminLineDeliveryController
{
    public function __invoke(Request $request): JsonResponse
    {
        $requestId=(string)Str::uuid7(); $request->attributes->set('v2_request_id',$requestId);
        try {
            $context=app(V2AdminFreshMfaAuthorizer::class)->context($request,$requestId);
            $s=app(V2LineDeliveryAdmin::class); $op=$request->route('operation');
            $write=!$request->isMethod('get') && $op!=='preview'; $s->authorize($context,$write);
            if (strlen($request->getContent())>131072) throw new \InvalidArgumentException;
            $input=$request->json()->all(); $id=$request->route('deliveryId');
            $cursor=$request->query('cursor','0');
            if (!is_string($cursor) || !ctype_digit($cursor) || strlen($cursor)>15) throw new \InvalidArgumentException;
            $data=match($op) {
                'index'=>$s->listing((int)$cursor), 'show'=>$s->detail($id,(int)$cursor),
                'save'=>$s->save($context,$id,$input), 'cancel','duplicate'=>$s->action($context,$id,$op,$input),
                'preview'=>$s->preview($input), 'defaults'=>$s->defaults(),
                'defaults-update'=>$this->defaults($s,$context,$input), 'quota'=>$s->quota(true),
                'baseline','halt'=>$s->quotaWrite($context,$op,$input), 'resend'=>$this->resend($s,$context,$request->route('batchId'),$input),
                'media'=>app(V2LineMediaManager::class)->intent($context,$input),
                'finalize'=>app(V2LineMediaManager::class)->finalize($context,$request->route('mediaId')),
                default=>throw new \InvalidArgumentException,
            };
            return response()->json($data,200,['Cache-Control'=>'private, no-store','X-Request-Id'=>$requestId]);
        } catch(V2AuthenticationException $e) { return V2ProblemDetails::fromAuthentication($request,$e); }
        catch(\Throwable $e) {
            $invalid=$e instanceof \InvalidArgumentException || $e instanceof \ValueError || $e instanceof \TypeError;
            $missing=$e instanceof \Illuminate\Database\RecordNotFoundException;
            $conflict=$e instanceof \RuntimeException && str_contains($e->getMessage(),'conflict');
            $status=$invalid ? 422 : ($missing ? 404 : ($conflict ? 409 : 503));
            $code=match($status) {422=>'LINE_DELIVERY_INPUT_INVALID',404=>'LINE_DELIVERY_NOT_FOUND',409=>'LINE_DELIVERY_REVISION_CONFLICT',default=>'LINE_DELIVERY_UNAVAILABLE'};
            return response()->json(['type'=>'https://oripa.example/problems/'.strtolower($code),'title'=>$code,'code'=>$code,'status'=>$status,'request_id'=>$requestId,'retryable'=>$status===503],$status,
                ['Content-Type'=>'application/problem+json','Cache-Control'=>'private, no-store']);
        }
    }

    private function defaults(V2LineDeliveryAdmin $s,$context,array $input): array
    {
        $s->keys($input,['expected_revision','pacing']); $s->integer($input['expected_revision']);
        $service=app(V2LineDeliveryService::class);
        $service->updateDefaults($context,$input['expected_revision'],$service->pacing(json_encode($input['pacing'],JSON_THROW_ON_ERROR)));
        return $s->defaults();
    }
    private function resend(V2LineDeliveryAdmin $s,$context,string $id,array $input): array
    {
        $s->keys($input,['request_key','confirmation']);
        $old=DB::table('line_delivery_batches')->where('public_id',$id)->whereIn('source_type',['delivery','manual'])->firstOrFail();
        // Only broadcast/manual chains are reachable; D4 automated/chat batches stay private.
        $root=$old; $depth=0;
        while($root->source_type==='manual' && $root->replaces_batch_id!==null && $depth++<100) $root=DB::table('line_delivery_batches')->where('id',$root->replaces_batch_id)->firstOrFail();
        if ($root->source_type!=='delivery') throw new \InvalidArgumentException;
        $new=app(V2LineDeliveryService::class)->resend($context,$old->id,$input['confirmation'],$input['request_key']);
        return ['id'=>DB::table('line_delivery_batches')->where('id',$new)->value('public_id')];
    }
}

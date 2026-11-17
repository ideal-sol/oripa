<?php

namespace App\Http\Controllers\V2;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Services\V2LineChatAdmin;
use App\Domain\Line\Services\V2LineTemplates;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminLineChatController
{
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $requestId=(string)Str::uuid7(); $request->attributes->set('v2_request_id',$requestId);
        try {
            $c=app(V2AdminFreshMfaAuthorizer::class)->context($request,$requestId);
            $s=app(V2LineChatAdmin::class); $t=app(V2LineTemplates::class);
            $operation=$request->route('operation'); $id=$request->route('conversationId');
            if (strlen($request->getContent())>100000) throw new \InvalidArgumentException;
            $input=$request->json()->all();
            $headers=['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','X-Request-Id'=>$requestId];
            if ($operation==='attachment') {
                $file=$s->attachment($c,$id,$request->route('messageId'));
                $safe=in_array($file['mime'],['image/png','image/jpeg','image/gif','image/webp','video/mp4','video/quicktime','audio/mpeg','audio/mp4','audio/x-m4a','audio/ogg','audio/wav','audio/x-wav'],true);
                return response($file['body'],200,[...$headers,'Content-Type'=>$safe ? $file['mime'] : 'application/octet-stream','Content-Disposition'=>'attachment; filename="line-attachment"','Content-Length'=>(string)strlen($file['body']),'Content-Security-Policy'=>"sandbox; default-src 'none'"]);
            }
            $data=match($operation) {
                'index'=>$s->listing($c,$this->number($request->query('page','1')),(string)$request->query('status',''),$request->boolean('unread'),(string)$request->query('search','')),
                'show'=>$s->detail($c,$id,$this->number($request->query('before','0'))),
                'media'=>$t->media($c,$this->number($request->query('page','1'))),
                'templates'=>$t->listing($c,$request->query('bot_id'),$request->query('category_id'),$this->number($request->query('page','1'))),
                'template-save'=>$t->save($c,$request->route('kind'),$request->route('recordId'),$input,$request->isMethod('delete')),
                default=>$this->mutate($s,$c,$id,$operation,$input),
            };
            return response()->json($data,200,$headers);
        } catch (V2AuthenticationException $e) {
            return V2ProblemDetails::fromAuthentication($request,$e);
        } catch (\Throwable $e) {
            $status=$e instanceof \InvalidArgumentException ? 422 : ($e instanceof \Illuminate\Database\RecordNotFoundException ? 404 : 409);
            $code='LINE_CHAT_REQUEST_REJECTED';
            return response()->json(['type'=>'https://oripa.example/problems/line-chat-request-rejected','title'=>$code,'code'=>$code,'status'=>$status,'request_id'=>$requestId,'retryable'=>false],$status,
                ['Content-Type'=>'application/problem+json','Cache-Control'=>'private, no-store']);
        }
    }
    private function number(mixed $n): int
    {
        if (! is_string($n) || ! preg_match('/\A[0-9]{1,15}\z/',$n)) throw new \InvalidArgumentException;
        return (int)$n;
    }
    private function mutate(V2LineChatAdmin $s, $c, string $id, string $operation, array $input): array
    {
        $keys=match($operation) {'read'=>['through'],'status'=>['status','expected_revision'],'reply'=>['messages','request_key'],default=>throw new \InvalidArgumentException};
        if (array_diff(array_keys($input),$keys) || array_diff($keys,array_keys($input))) throw new \InvalidArgumentException;
        if ($operation==='read') {
            if (! is_int($input['through'])) throw new \InvalidArgumentException;
            $s->markRead($c,$id,$input['through']);
        } elseif ($operation==='status') {
            if (! is_string($input['status']) || ! is_int($input['expected_revision'])) throw new \InvalidArgumentException;
            $s->status($c,$id,$input['status'],$input['expected_revision']);
        } else {
            if (! is_array($input['messages']) || ! is_string($input['request_key'])) throw new \InvalidArgumentException;
            $id=$s->reply($c,$id,$input['messages'],$input['request_key']);
        }
        return ['id'=>$id];
    }
}

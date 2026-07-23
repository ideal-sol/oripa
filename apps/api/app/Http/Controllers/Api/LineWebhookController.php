<?php

namespace App\Http\Controllers\Api;

use App\Domain\Line\Services\LineFriendLinkService;
use App\Domain\Line\Services\LineMessagingService;
use App\Models\LineFriendSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LineWebhookController extends Controller
{
    public function __invoke(Request $request, LineMessagingService $messagingService, LineFriendLinkService $lineFriendLinkService): JsonResponse
    {
        $body = $request->getContent();

        if (! $messagingService->verifySignature($body, $request->header('X-Line-Signature'))) {
            return response()->json(['message' => 'Invalid LINE signature.'], 403);
        }

        $events = (array) $request->input('events', []);
        $lineFriendLinkService->handleEvents($events);

        $setting = LineFriendSetting::current();
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'follow' && $setting->auto_reply_message) {
                $messagingService->replyText($event['replyToken'] ?? null, $setting->auto_reply_message);
            }
        }

        return response()->json(['message' => 'OK']);
    }
}

<?php

namespace App\Domain\Line\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class V2LineChatReceiver
{
    /** Invoked only after existing signature validation. No follower state is invented. */
    public function handle(#[\SensitiveParameter] array $events, int $botId, int $generation): void
    {
        $deadline = hrtime(true) + 8_000_000_000;
        foreach (array_slice($events, 0, 100) as $event) {
            // Previously committed events are deduplicated on provider redelivery.
            if (hrtime(true) >= $deadline) throw new \RuntimeException('LINE_CHAT_RECEIVE_DEADLINE');
            if (! is_array($event) || ! in_array($event['type'] ?? null, ['message', 'unsend'], true)
                || ($event['source']['type'] ?? null) !== 'user') continue;
            $subject = $event['source']['userId'] ?? null;
            $eventId = $event['webhookEventId'] ?? null;
            $unsent = $event['type'] === 'unsend';
            $id = $unsent ? ($event['unsend']['messageId'] ?? null) : ($event['message']['id'] ?? null);
            if (! is_string($subject) || ! preg_match('/\AU[0-9a-f]{32}\z/i', $subject)
                || ! is_string($eventId) || ! preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $eventId)
                || ! is_string($id) || ! preg_match('/\A[0-9]{1,128}\z/', $id)
                || ! is_int($event['timestamp'] ?? null) || $event['timestamp'] < 0 || $event['timestamp'] > now()->addDay()->getTimestampMs()) continue;
            $hash = app(V2LineSubjectVault::class)->canonicalHash($subject);
            $fetch = DB::transaction(function () use ($event, $eventId, $id, $hash, $botId, $generation, $unsent): ?array {
                DB::statement("SET LOCAL lock_timeout = '500ms'");
                DB::statement("SET LOCAL statement_timeout = '1500ms'");
                app(V2LineMessagingSettingService::class)->setting(true);
                $bot = DB::table('line_bots')->where('id', $botId)->where('is_active', true)->where('authentication_revision', $generation)->first();
                if ($bot === null || DB::table('line_chat_events')->where('line_bot_id', $botId)->where('event_id', $eventId)->exists()) return null;
                $at = CarbonImmutable::createFromTimestampMs($event['timestamp'])->utc()->format('Y-m-d H:i:s.uP');
                $existing = DB::table('line_chat_messages')->where('line_bot_id', $botId)->where('line_message_id', $id)->first();
                if ($existing !== null) {
                    $conversation = DB::table('line_conversations')->where('id', $existing->conversation_id)->firstOrFail();
                    if (! hash_equals($conversation->subject_hash, $hash)) return null;
                    if ($unsent && ! $existing->unsent) DB::table('line_chat_messages')->where('id', $existing->id)->update([
                        'unsent' => true, 'body_ciphertext' => null, 'media_key' => null, 'media_mime' => null, 'media_bytes' => null, 'media_state' => 'cancelled', 'read_at' => now(),
                    ]);
                    DB::table('line_chat_events')->insert(['line_bot_id' => $botId, 'event_id' => $eventId, 'message_id' => $existing->id, 'event_type' => $event['type']]);
                    return null;
                }
                $conversation = DB::table('line_conversations')->where('line_bot_id', $botId)->where('subject_hash', $hash)->first();
                $cid = $conversation?->id ?? DB::table('line_conversations')->insertGetId(['public_id' => (string) Str::uuid(), 'line_bot_id' => $botId, 'subject_hash' => $hash, 'last_message_at' => $at]);
                $m = $event['message'] ?? [];
                $type = $unsent ? 'other' : ($m['type'] ?? 'other');
                if (! in_array($type, ['text','sticker','image','video','audio','file','location'], true)) $type = 'other';
                $body = match ($type) {
                    'text' => ['text' => mb_substr(is_string($m['text'] ?? null) ? $m['text'] : '', 0, 10000)],
                    'sticker' => array_filter(array_intersect_key($m, array_flip(['packageId','stickerId'])), static fn ($v) => is_string($v) && preg_match('/\A[0-9]{1,32}\z/', $v)),
                    'location' => ['title' => mb_substr(is_string($m['title'] ?? null) ? $m['title'] : '', 0, 100), 'address' => mb_substr(is_string($m['address'] ?? null) ? $m['address'] : '', 0, 500)],
                    default => [],
                };
                $media = in_array($type, ['image','video','audio','file'], true);
                // File messages have no contentProvider; image/video/audio must explicitly be LINE-hosted.
                $canFetch = $media && ($type === 'file' ? ! isset($m['contentProvider']) : ($m['contentProvider']['type'] ?? null) === 'line');
                $mid = DB::table('line_chat_messages')->insertGetId(['public_id' => (string) Str::uuid(), 'conversation_id' => $cid,
                    'line_bot_id' => $botId, 'authentication_revision' => $generation, 'direction' => 'inbound', 'line_message_id' => $id,
                    'type' => $type, 'unsent' => $unsent, 'body_ciphertext' => $unsent ? null : Crypt::encryptString(json_encode($body, JSON_THROW_ON_ERROR)),
                    'media_state' => $unsent ? 'cancelled' : ($canFetch ? 'fetching' : ($media ? 'unavailable' : 'none')), 'received_at' => $at]);
                DB::table('line_chat_events')->insert(['line_bot_id' => $botId, 'event_id' => $eventId, 'message_id' => $mid, 'event_type' => $event['type']]);
                DB::table('line_conversations')->where('id', $cid)->where('last_message_at', '<', $at)->update(['last_message_at' => $at]);
                return $canFetch ? [$bot, DB::table('line_chat_messages')->where('id', $mid)->firstOrFail()] : null;
            }, 1);
            if ($fetch === null) continue;
            [$bot, $message] = $fetch;
            $saved = hrtime(true) >= $deadline ? ['media_state' => 'unavailable'] : app(V2LineChatMedia::class)->receive($bot, $message, $deadline);
            $accepted = DB::transaction(function () use ($botId, $generation, $message, $saved): bool {
                DB::statement("SET LOCAL lock_timeout = '500ms'");
                DB::statement("SET LOCAL statement_timeout = '1500ms'");
                app(V2LineMessagingSettingService::class)->setting(true);
                $active = DB::table('line_bots')->where('id', $botId)->where('is_active', true)->where('authentication_revision', $generation)->exists();
                return DB::table('line_chat_messages')->where('id', $message->id)->where('unsent', false)->where('media_state', 'fetching')
                    ->update($active ? $saved : ['media_state' => 'unavailable']) === 1 && $active;
            }, 1);
            if (! $accepted && isset($saved['media_key'])) Storage::disk('local')->delete($saved['media_key']);
        }
    }
}

<?php

namespace App\Domain\Line\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class V2LineChatMedia
{
    /** No public URL, original filename, external provider URL or content in diagnostics. */
    public function receive(object $bot, object $message, int $deadline): array
    {
        $file = tempnam(sys_get_temp_dir(), 'line-chat-');
        if ($file === false) return ['media_state' => 'unavailable'];
        chmod($file, 0600);
        try {
            $result = app(V2LineBotTransport::class)->chatContent($bot, $message->line_message_id, $file, $deadline);
            if ($result['state'] !== 'stored') return ['media_state' => $result['state']];
            clearstatcache(true, $file);
            $size = filesize($file);
            // Crypt envelopes expand bytes;8MiB leaves headroom under the existing128MiB PHP limit.
            if ($size < 1 || $size > 8388608) return ['media_state' => 'unavailable'];
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
            $allowed = match ($message->type) {
                'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                'video' => ['video/mp4', 'video/quicktime'],
                'audio' => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/wav', 'audio/x-wav'],
                'file' => ['application/pdf', 'application/zip', 'application/octet-stream', 'text/plain', 'image/jpeg', 'image/png'],
                default => [],
            };
            if (! in_array($mime, $allowed, true) || ($result['mime'] !== $mime && ! in_array($result['mime'], ['application/octet-stream', 'audio/mp4'], true))) return ['media_state' => 'unavailable'];
            if ($message->type === 'image') {
                $info = @getimagesize($file);
                if ($info === false || $info[0] * $info[1] > 40000000 || $info['mime'] !== $mime) return ['media_state' => 'unavailable'];
            }
            $key = 'line-chat/'.$bot->public_id.'/'.$message->conversation_id.'/'.$message->public_id.'/'.Str::uuid().'.bin';
            $encrypted = Crypt::encryptString(file_get_contents($file));
            $disk = Storage::disk('local');
            if (! $disk->put($key, $encrypted, ['visibility' => 'private']) || ! $disk->exists($key) || $disk->size($key) !== strlen($encrypted)) {
                $disk->delete($key);
                return ['media_state' => 'unavailable'];
            }
            return ['media_state' => 'stored', 'media_key' => $key, 'media_mime' => $mime, 'media_bytes' => $size];
        } catch (\Throwable) {
            return ['media_state' => 'unavailable'];
        } finally {
            if (is_file($file)) unlink($file);
        }
    }
}

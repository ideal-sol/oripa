<?php

namespace App\Domain\Line\Services;

/** Immutable server manifests are the authority; request flags and URLs are not. */
class V2LineDeliveryMedia
{
    public function assertVerified(#[\SensitiveParameter] array $message): void
    {
        $urls = match ($message['type'] ?? '') {
            'image', 'video' => [$message['originalContentUrl'] ?? ''],
            'imagemap' => [$message['baseUrl'] ?? ''],
            'image_carousel' => array_column($message['columns'] ?? [], 'imageUrl'),
            default => throw new \InvalidArgumentException('LINE_MEDIA_INVALID'),
        };
        $origin = app(V2LineMediaManager::class)->origin();
        $bot = \Illuminate\Support\Facades\DB::table('line_bots')->where('is_active', true)->where('connection_status', 'succeeded')->firstOrFail();
        foreach ($urls as $url) {
            if (! is_string($url) || ! preg_match('#\A'.preg_quote($origin, '#').'/line-delivery/([0-9a-f-]{36})(?:/original)?\z#', $url, $match)) throw new \InvalidArgumentException('LINE_MEDIA_INVALID');
            $asset = \Illuminate\Support\Facades\DB::table('line_media_assets')->where('id', $match[1])->where('line_bot_id', $bot->id)
                ->where('authentication_revision', $bot->authentication_revision)->firstOrFail();
            $manifest = json_decode($asset->manifest, true, 32, JSON_THROW_ON_ERROR);
            $kind = $message['type'] === 'image_carousel' ? 'carousel' : $message['type'];
            if ($asset->kind !== $kind || $manifest['original_url'] !== $url
                || (isset($message['previewImageUrl']) && $message['previewImageUrl'] !== $manifest['preview_url'])
                || ($kind === 'imagemap' && ($message['height'] !== $manifest['height'] || $message['layout'] !== 'full')))
                throw new \InvalidArgumentException('LINE_MEDIA_INVALID');
        }
    }
}

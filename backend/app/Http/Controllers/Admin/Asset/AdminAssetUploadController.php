<?php

namespace App\Http\Controllers\Admin\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminAssetUploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $fileField = $request->hasFile('video') ? 'video' : 'image';

        $payload = $request->validate([
            'context' => ['nullable', 'string', Rule::in(['gacha', 'rank', 'prize', 'draw-video', 'announcement'])],
            'image' => [$fileField === 'image' ? 'required' : 'sometimes', 'image', 'max:5120'],
            'video' => [$fileField === 'video' ? 'required' : 'sometimes', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
        ]);

        $context = $payload['context'] ?? 'gacha';
        $file = $request->file($fileField);
        $extension = $file->extension() ?: ($fileField === 'video' ? 'mp4' : 'jpg');
        $path = sprintf(
            'admin-assets/%s/%s/%s.%s',
            $context,
            now()->format('Y/m'),
            (string) Str::uuid(),
            $extension,
        );

        Storage::disk(config('filesystems.default'))->put($path, $file->get(), [
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => $this->assetUrl($request, $path),
                'type' => $fileField,
            ],
        ], 201);
    }

    private function assetUrl(Request $request, string $path): string
    {
        $scheme = explode(',', (string) $request->headers->get('x-forwarded-proto', $request->getScheme()))[0] ?: 'http';
        $host = $request->getHost();

        if (str_starts_with($host, 'admin.')) {
            $host = substr($host, strlen('admin.'));
        }

        if (! str_contains($host, 'localhost') && ! str_starts_with($host, '127.')) {
            $scheme = 'https';
        }

        return sprintf('%s://%s/api/assets/%s', $scheme, $host, ltrim($path, '/'));
    }
}

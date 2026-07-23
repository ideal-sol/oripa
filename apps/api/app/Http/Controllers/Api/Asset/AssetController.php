<?php

namespace App\Http\Controllers\Api\Asset;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AssetController extends Controller
{
    public function __invoke(string $path): Response
    {
        abort_if(str_contains($path, '..') || str_starts_with($path, '/'), 404);

        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException();
        }

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}

<?php

namespace App\Domain\Catalog\Services;

use InvalidArgumentException;

final class V2AssetPublicPathResolver
{
    public function enabled(): bool
    {
        $configured = config('v2_assets.public_base_url');
        if ($configured === null || $configured === '') {
            return false;
        }
        $this->baseUrl();
        if (config('filesystems.default') !== 's3') {
            throw new InvalidArgumentException('Asset CDN requires the S3 disk.');
        }

        return true;
    }

    public function path(string $key): string
    {
        if (strlen($key) > 1024 || preg_match('/[^A-Za-z0-9\/_.-]/', $key)
            || ! preg_match('#\Aadmin-assets/(gacha|top-banner|rank-masters|rank-effects)/#', $key)) {
            throw new InvalidArgumentException('Unsupported Asset key.');
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid Asset key.');
            }
        }
        $path = substr($key, strlen('admin-assets'));
        if (strlen($path) > 512) {
            throw new InvalidArgumentException('Asset path exceeds the API contract.');
        }

        return $path;
    }

    public function url(string $key): string
    {
        return $this->baseUrl().$this->path($key);
    }

    public function baseUrl(): string
    {
        $value = config('v2_assets.public_base_url');
        if (! is_string($value) || ! preg_match('#\Ahttps://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/?\z#', $value)) {
            throw new InvalidArgumentException('Invalid Asset CDN origin.');
        }
        $origin = rtrim($value, '/');
        if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Invalid Asset CDN origin.');
        }

        $host = strtolower(parse_url($origin, PHP_URL_HOST));
        $port = parse_url($origin, PHP_URL_PORT);

        return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
    }
}

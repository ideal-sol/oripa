<?php

namespace App\Domain\Line\Services;

use Illuminate\Support\Facades\Cache;

final class V2LineCallbackNotice
{
    private const CODES = ['friendship_check_failed', 'reward_apply_failed', 'line_auth_failed'];

    public function put(string $sessionHash, ?string $code): void
    {
        if (in_array($code, self::CODES, true)) Cache::put('line-callback:'.$sessionHash, $code, 120);
        else Cache::forget('line-callback:'.$sessionHash);
    }

    public function consume(?string $sessionHash): ?string
    {
        if ($sessionHash === null) return null;
        return Cache::lock('line-callback-lock:'.$sessionHash, 5)->block(1, function () use ($sessionHash): ?string {
            $code = Cache::pull('line-callback:'.$sessionHash);
            return in_array($code, self::CODES, true) ? $code : null;
        });
    }
}

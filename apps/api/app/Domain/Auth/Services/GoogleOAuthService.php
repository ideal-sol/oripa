<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\DTO\SocialUserProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleOAuthService
{
    public function authorizationUrl(): array
    {
        $state = Str::random(48);
        Cache::put($this->stateCacheKey($state), true, now()->addMinutes(10));

        return [
            'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id' => config('services.google.client_id'),
                'redirect_uri' => config('services.google.redirect_uri'),
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'access_type' => 'offline',
                'prompt' => 'select_account',
            ]),
            'state' => $state,
        ];
    }

    public function fetchUserProfile(string $code, string $state): SocialUserProfile
    {
        if (! Cache::pull($this->stateCacheKey($state))) {
            throw ValidationException::withMessages([
                'state' => ['Googleログインの状態確認に失敗しました。もう一度お試しください。'],
            ]);
        }

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $tokenResponse->successful()) {
            throw ValidationException::withMessages([
                'code' => ['Googleログインの認証コードを確認できませんでした。'],
            ]);
        }

        $accessToken = (string) $tokenResponse->json('access_token');

        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'code' => ['Googleログインのアクセストークンを取得できませんでした。'],
            ]);
        }

        $profileResponse = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (! $profileResponse->successful()) {
            throw ValidationException::withMessages([
                'code' => ['Googleアカウント情報を取得できませんでした。'],
            ]);
        }

        $profile = $profileResponse->json();
        $providerUserId = (string) ($profile['sub'] ?? '');
        $email = mb_strtolower(trim((string) ($profile['email'] ?? '')));

        if ($providerUserId === '' || $email === '') {
            throw ValidationException::withMessages([
                'email' => ['Googleアカウントのメールアドレスを取得できませんでした。'],
            ]);
        }

        return new SocialUserProfile(
            provider: 'google',
            providerUserId: $providerUserId,
            email: $email,
            emailVerified: (bool) ($profile['email_verified'] ?? false),
            name: $profile['name'] ?? null,
            avatarUrl: $profile['picture'] ?? null,
            raw: $profile,
        );
    }

    private function stateCacheKey(string $state): string
    {
        return "oauth:google:state:{$state}";
    }
}

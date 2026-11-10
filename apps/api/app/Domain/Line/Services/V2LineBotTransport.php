<?php

namespace App\Domain\Line\Services;

use App\Domain\Line\Exceptions\V2LineMessagingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class V2LineBotTransport
{
    private const HOST = 'https://api.line.me';

    public function get(#[SensitiveParameter] object $bot, string $resource, array $query = []): array
    {
        if (! in_array($resource, ['/v2/bot/info', '/v2/bot/message/quota', '/v2/bot/message/quota/consumption', '/v2/bot/insight/followers'], true)) {
            throw $this->unavailable();
        }
        return $this->request($bot, 'get', $resource, $query);
    }

    public function reply(#[SensitiveParameter] object $bot, #[SensitiveParameter] string $replyToken, string $message): void
    {
        $this->request($bot, 'post', '/v2/bot/message/reply', [
            'replyToken' => $replyToken, 'messages' => [['type' => 'text', 'text' => $message]],
        ]);
    }

    private function request(#[SensitiveParameter] object $bot, string $method, string $path, #[SensitiveParameter] array $input): array
    {
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $token = $this->token($bot, $attempt === 1);
                $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                    ->connectTimeout(3)->timeout(3)->{$method}(self::HOST.$path, $input);
                unset($token);
                if ($response->status() === 401 && $attempt === 0) continue;
                if (! $response->successful() || ! is_array($response->json())) throw $this->unavailable();
                return $response->json();
            }
        } catch (Throwable) {
            // Never propagate HTTP exceptions, request payloads or provider bodies.
            throw $this->unavailable();
        }
        throw $this->unavailable();
    }

    private function token(#[SensitiveParameter] object $bot, bool $refresh): string
    {
        $key = 'v2:line:token:'.$bot->public_id.':'.$bot->revision.':'.hash('sha256', (string) $bot->channel_secret_ciphertext);
        if ($refresh) Cache::forget($key);
        $cached = Cache::get($key);
        if (is_string($cached)) return Crypt::decryptString($cached);
        if (! is_string($bot->channel_id) || ! is_string($bot->channel_secret_ciphertext)) throw $this->unavailable();
        $response = Http::asForm()->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(3)
            ->post(self::HOST.'/oauth2/v3/token', [
                'grant_type' => 'client_credentials', 'client_id' => $bot->channel_id,
                'client_secret' => Crypt::decryptString($bot->channel_secret_ciphertext),
            ]);
        $token = $response->json('access_token');
        $expires = $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_int($expires) || $expires <= 120) throw $this->unavailable();
        Cache::put($key, Crypt::encryptString($token), min($expires, 900) - 120);
        return $token;
    }

    private function unavailable(): V2LineMessagingException
    {
        return new V2LineMessagingException('LINE_PROVIDER_UNAVAILABLE', 503, 'LINE connection failed.', true);
    }
}

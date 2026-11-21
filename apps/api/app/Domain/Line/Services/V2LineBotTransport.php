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

    /** One explicitly selected known identity; no follower-list entitlement required. */
    public function profileName(#[SensitiveParameter] object $bot, #[SensitiveParameter] string $subject): ?string
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0 || ! preg_match('/\AU[0-9a-f]{32}\z/', $subject)) return null;
        try {
            $response = Http::acceptJson()->withToken($this->token($bot, false))->withoutRedirecting()
                ->connectTimeout(3)->timeout(3)->withOptions([
                    'on_headers'=>static function ($r): void { if ((int) $r->getHeaderLine('Content-Length') > 16384) throw new \RuntimeException('Profile limit.'); },
                    'progress'=>static function ($total, $downloaded): void { if ($total > 16384 || $downloaded > 16384) throw new \RuntimeException('Profile limit.'); },
                ])->get(self::HOST.'/v2/bot/profile/'.$subject);
            if ($response->status() !== 200 || strlen($response->body()) > 16384 || $response->json('userId') !== $subject) return null;
            return V2LineProfileName::normalize($response->json('displayName'));
        } catch (Throwable) { return null; }
    }
    /** D7 only: no DB transaction, redirect, provider prose or token escapes. */
    public function followerIds(#[SensitiveParameter] object $bot, #[SensitiveParameter] ?string $next): array
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) return ['status' => 0];
        try {
            $query = ['limit' => 1000];
            if ($next !== null) $query['start'] = $next;
            $response = Http::acceptJson()->withToken($this->token($bot, false))->withoutRedirecting()
                ->connectTimeout(3)->timeout(10)->withOptions([
                    'decode_content' => false,
                    'on_headers' => static function ($r): void {
                        if ((int) $r->getHeaderLine('Content-Length') > 131072) throw new \RuntimeException('Response limit.');
                    },
                    'progress' => static function ($total, $downloaded): void {
                        if ($total > 131072 || $downloaded > 131072) throw new \RuntimeException('Response limit.');
                    },
                ])->get(self::HOST.'/v2/bot/followers/ids', $query);
            if ($response->status() !== 200) return ['status' => $response->status()];
            if (strlen($response->body()) > 131072) return ['status' => 0];
            // Preserve JSON object/array distinction, including an empty userIds object.
            $decoded = json_decode($response->body(), false, 32, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) return ['status' => 0];
            $body = get_object_vars($decoded);
            if (! is_array($body) || array_diff(array_keys($body), ['userIds', 'next']) !== []
                || ! isset($body['userIds']) || ! is_array($body['userIds']) || ! array_is_list($body['userIds'])
                || count($body['userIds']) > 1000) return ['status' => 0];
            foreach ($body['userIds'] as $id) {
                if (! is_string($id) || ! preg_match('/\AU[0-9a-f]{32}\z/', $id)) return ['status' => 0];
            }
            if (array_key_exists('next', $body) && (! is_string($body['next'])
                || ! preg_match('/\A[\x21-\x7e]{1,2048}\z/', $body['next']))) return ['status' => 0];
            return ['status' => 200, 'userIds' => $body['userIds'], 'next' => $body['next'] ?? null];
        } catch (Throwable) {
            return ['status' => 0];
        }
    }

    private array $deliveryTokens = [];
    private ?array $refreshedDeliveryToken = null;

    public function __debugInfo(): array { return ['credentials' => '[redacted]']; }

    /** Fixed host, bounded streamed content; caller owns and removes the private temp file. */
    public function chatContent(#[SensitiveParameter] object $bot, string $id, string $destination, int $deadline): array
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0 || ! preg_match('/\A[0-9]{1,128}\z/', $id)) return ['state' => 'unavailable'];
        try {
            if ($deadline - hrtime(true) < 3_100_000_000) return ['state' => 'unavailable'];
            $token = $this->token($bot, false);
            $remaining = min(4, ($deadline - hrtime(true)) / 1_000_000_000);
            if ($remaining <= 0) return ['state' => 'unavailable'];
            $response = Http::withToken($token)->withoutRedirecting()->connectTimeout(min(1, $remaining))->timeout($remaining)
                ->withOptions(['sink' => $destination, 'decode_content' => false, 'on_headers' => static function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 8388608) throw new \RuntimeException('Content limit.');
                }, 'progress' => static function ($total, $downloaded) use ($deadline): void {
                    if ($total > 8388608 || $downloaded > 8388608 || hrtime(true) >= $deadline) throw new \RuntimeException('Content limit.');
                }])->get('https://api-data.line.me/v2/bot/message/'.$id.'/content');
            unset($token);
            if ($response->status() === 202) return ['state' => 'pending_external'];
            clearstatcache(true, $destination);
            if ($response->status() !== 200 || filesize($destination) < 1 || filesize($destination) > 8388608) return ['state' => 'unavailable'];
            return ['state' => 'stored', 'mime' => strtolower(trim(explode(';', $response->header('Content-Type'))[0]))];
        } catch (Throwable) {
            return ['state' => 'unavailable'];
        }
    }

    public function cacheRefreshedDeliveryToken(): void
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) throw $this->unavailable();
        if ($this->refreshedDeliveryToken === null) return;
        [$key, $token, $expires] = $this->refreshedDeliveryToken;
        $this->refreshedDeliveryToken = null;
        Cache::put($key, Crypt::encryptString($token), min($expires, 900) - 120);
    }

    public function warmDeliveryToken(#[SensitiveParameter] object $bot): void
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) throw $this->unavailable();
        $this->deliveryTokens = [$bot->id.':'.$bot->authentication_revision => $this->token($bot, false)];
    }

    /** Frozen JSON bytes only. Total dispatch budget includes token renewal; no DB retry. */
    public function deliver(#[SensitiveParameter] object $bot, string $method, string $retryKey, #[SensitiveParameter] string $json): array
    {
        if (! in_array($method, ['push', 'multicast'], true)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $retryKey)) throw $this->unavailable();
        $deadline = hrtime(true) + 10_000_000_000;
        $lastStatus = 0;
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                // Existing token request has a three-second upper bound.
                if ($deadline - hrtime(true) < 3_100_000_000) return ['status' => $lastStatus, 'request_id' => null];
                if ($attempt === 0) $token = $this->deliveryTokens[$bot->id.':'.$bot->authentication_revision] ?? null;
                else {
                    [$token, $expires] = $this->issueToken($bot);
                    $this->refreshedDeliveryToken = [$this->cacheKey($bot), $token, $expires];
                }
                if (! is_string($token)) throw $this->unavailable();
                $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
                if ($remaining <= 0) return ['status' => 0, 'request_id' => null];
                // Once another request starts, an earlier401 says nothing about
                // whether this attempt was accepted before a connection failure.
                $lastStatus = 0;
                $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                    ->connectTimeout(min(5, $remaining))->timeout($remaining)
                    ->withHeaders(['X-Line-Retry-Key' => $retryKey])->withBody($json, 'application/json')
                    ->post(self::HOST.'/v2/bot/message/'.$method);
                unset($token);
                $lastStatus = $response->status();
                if ($response->status() === 401 && $attempt === 0) continue;
                $id = $response->header($response->status() === 409 ? 'x-line-accepted-request-id' : 'x-line-request-id');
                return ['status' => $response->status(), 'request_id' => is_string($id) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $id) ? $id : null];
            }
        } catch (Throwable) {
            // No request, recipient, token, provider body or exception chain escapes.
        }
        return ['status' => $lastStatus === 401 ? 401 : 0, 'request_id' => null];
    }

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
        $key = $this->cacheKey($bot);
        if ($refresh) Cache::forget($key);
        $cached = Cache::get($key);
        if (is_string($cached)) return Crypt::decryptString($cached);
        [$token, $expires] = $this->issueToken($bot);
        Cache::put($key, Crypt::encryptString($token), min($expires, 900) - 120);
        return $token;
    }

    private function issueToken(#[SensitiveParameter] object $bot): array
    {
        if (! is_string($bot->channel_id) || ! is_string($bot->channel_secret_ciphertext)) throw $this->unavailable();
        $response = Http::asForm()->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(3)
            ->post(self::HOST.'/oauth2/v3/token', [
                'grant_type' => 'client_credentials', 'client_id' => $bot->channel_id,
                'client_secret' => Crypt::decryptString($bot->channel_secret_ciphertext),
            ]);
        $token = $response->json('access_token');
        $expires = $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_int($expires) || $expires <= 120) throw $this->unavailable();
        return [$token, $expires];
    }

    private function cacheKey(#[SensitiveParameter] object $bot): string
    {
        return 'v2:line:token:'.$bot->public_id.':'.$bot->revision.':'.hash('sha256', (string) $bot->channel_secret_ciphertext);
    }

    private function unavailable(): V2LineMessagingException
    {
        return new V2LineMessagingException('LINE_PROVIDER_UNAVAILABLE', 503, 'LINE connection failed.', true);
    }
}

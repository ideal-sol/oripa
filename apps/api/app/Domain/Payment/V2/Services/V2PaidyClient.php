<?php

namespace App\Domain\Payment\V2\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class V2PaidyClient
{
    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\Apay_[A-Za-z0-9_-]{1,124}\z/', $id) === 1;
    }

    /** No transport retries for money-moving requests. No provider text escapes. */
    public function request(string $id, string $operation = 'lookup'): array
    {
        if (DB::transactionLevel() !== 0 || ! self::validId($id)
            || ! in_array($operation, ['lookup', 'capture', 'cancel'], true)) {
            throw new \LogicException('Paidy communication boundary is invalid.');
        }
        app(V2PaidyConfiguration::class)->validate();
        try {
            $request = Http::withToken(config('v2_paidy.secret_key'))
                ->withHeaders(['Paidy-Version' => config('v2_paidy.api_version')])
                ->acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting();
            $url = config('v2_paidy.api_base_url').'/payments/'.$id;
            $response = $operation === 'lookup' ? $request->get($url)
                : $request->withBody('{}', 'application/json')->send('POST', $url.($operation === 'capture' ? '/captures' : '/close'));
            $body = self::decode($response->body());
            return [
                'http_status' => $response->status(),
                'body' => $response->successful() && is_array($body) ? $body : null,
                'authorization_expired' => $response->status() === 400 && is_array($body)
                    && ($body['code'] ?? null) === 'payment.authorization.expired',
            ];
        } catch (\Illuminate\Http\Client\ConnectionException|\JsonException $exception) {
            return ['http_status' => null, 'body' => null, 'authorization_expired' => false];
        }
    }

    /** Preserve number lexemes so sub-JPY fractions cannot round to an integer. */
    public static function decode(string $json): ?array
    {
        try {
            // Validate the original JSON before transforming any number tokens.
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $preserved = ''; $quoted = false;
            for ($index = 0, $length = strlen($json); $index < $length; $index++) {
                $character = $json[$index];
                if ($quoted && $character === '\\') {
                    $preserved .= $character.$json[++$index];
                    continue;
                }
                if ($character === '"') $quoted = ! $quoted;
                if (! $quoted && ($character === '-' || ctype_digit($character))) {
                    $count = strspn($json, '0123456789.eE+-', $index);
                    $preserved .= json_encode(substr($json, $index, $count), JSON_THROW_ON_ERROR);
                    $index += $count - 1;
                } else $preserved .= $character;
            }
            $body = json_decode($preserved, true, 512, JSON_THROW_ON_ERROR);
            return is_array($body) ? $body : null;
        } catch (\JsonException) { return null; }
    }
}

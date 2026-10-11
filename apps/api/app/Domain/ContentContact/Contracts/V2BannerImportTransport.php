<?php

namespace App\Domain\ContentContact\Contracts;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use SensitiveParameter;

class V2BannerImportTransport
{
    public function fetch(#[SensitiveParameter] string $url, #[SensitiveParameter] string $host, string $address, int $timeoutMs): string
    {
        V2BannerImportExecution::assertEnabled();
        if (! function_exists('curl_init') || $timeoutMs < 1) {
            throw new V2ContentContactException('IMAGE_FETCH_FAILED', 422, '画像を取得できませんでした。');
        }
        $bytes = '';
        $tooLarge = false;
        $badEncoding = false;
        $response = $this->transfer([
                CURLOPT_URL => $url,
                CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)],
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: image/gif, image/jpeg, image/png, image/webp', 'Accept-Encoding: identity'],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$badEncoding): int {
                    if (preg_match('/^Content-Encoding:\s*(.+)/i', $header, $match)
                        && strtolower(trim($match[1])) !== 'identity') {
                        $badEncoding = true;
                        return 0;
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes, &$tooLarge): int {
                    if (strlen($bytes) + strlen($chunk) > 5242880) {
                        $tooLarge = true;
                        return 0;
                    }
                    $bytes .= $chunk;
                    return strlen($chunk);
                },
        ]);
        if ($tooLarge) {
            throw new V2ContentContactException('IMAGE_TOO_LARGE', 422, '画像は5MiB以下で指定してください。');
        }
        if (! $response['success'] || $badEncoding || $response['status'] !== 200
            || @inet_pton($response['address']) !== @inet_pton($address)) {
            throw new V2ContentContactException('IMAGE_FETCH_FAILED', 422, '画像を取得できませんでした。');
        }
        return $bytes;
    }

    protected function transfer(#[SensitiveParameter] array $options): array
    {
        $handle = curl_init();
        try {
            curl_setopt_array($handle, $options);
            $success = curl_exec($handle) !== false;
            return ['success' => $success, 'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'address' => (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP)];
        } finally {
            curl_close($handle);
        }
    }
}

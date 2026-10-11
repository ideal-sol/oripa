<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\ContentContact\Contracts\V2BannerImportExecution;
use App\Domain\ContentContact\Contracts\V2BannerImportResolver;
use App\Domain\ContentContact\Contracts\V2BannerImportTransport;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use SensitiveParameter;

final class V2BannerImportImageFetcher
{
    public function __construct(
        private readonly V2BannerImportUrlPolicy $policy,
        private readonly V2BannerImportResolver $resolver,
        private readonly V2BannerImportTransport $transport,
    ) {}

    public function fetch(#[SensitiveParameter] string $url): array
    {
        V2BannerImportExecution::assertEnabled();
        $started = hrtime(true);
        try {
            $host = $this->policy->validate($url);
            $addresses = $this->resolver->resolve($host);
            if ($addresses === [] || count($addresses) > 128) {
                throw new V2ContentContactException('IMAGE_FETCH_FAILED', 422, '画像を取得できませんでした。');
            }
            foreach ($addresses as $address) {
                $this->policy->assertPublicIp($address);
            }
            $remaining = 20000 - (int) ((hrtime(true) - $started) / 1000000);
            if ($remaining < 1) {
                throw new V2ContentContactException('IMAGE_FETCH_FAILED', 422, '画像を取得できませんでした。');
            }
            $bytes = $this->transport->fetch($url, $host, $addresses[0], $remaining);
            if (strlen($bytes) > 5242880) {
                throw new V2ContentContactException('IMAGE_TOO_LARGE', 422, '画像は5MiB以下で指定してください。');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (! in_array($mime, ['image/gif', 'image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new V2ContentContactException('IMAGE_TYPE_NOT_ALLOWED', 422, '画像形式を確認してください。');
            }
            return ['bytes' => $bytes, 'mime_type' => $mime, 'checksum_sha256' => hash('sha256', $bytes)];
        } catch (V2ContentContactException $exception) {
            throw new V2ContentContactException($exception->errorCode, $exception->status, $exception->getMessage());
        } catch (\Throwable) {
            throw new V2ContentContactException('IMAGE_FETCH_FAILED', 422, '画像を取得できませんでした。');
        }
    }
}

<?php

namespace App\Domain\ContentContact\Contracts;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use SensitiveParameter;
use Symfony\Component\Process\Process;

class V2BannerImportResolver
{
    public function resolve(#[SensitiveParameter] string $host): array
    {
        V2BannerImportExecution::assertEnabled();
        $process = new Process([PHP_BINARY, '-r',
            '$records = @dns_get_record($argv[1], DNS_A | DNS_AAAA); echo json_encode(array_values(array_filter(array_map(static fn ($record) => $record["ip"] ?? $record["ipv6"] ?? null, $records ?: []))));',
            $host], null, [], null, 5);
        try {
            $process->run();
            $addresses = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (! $process->isSuccessful() || ! is_array($addresses) || $addresses === [] || count($addresses) > 128) {
                throw new \RuntimeException();
            }
            return array_values(array_unique($addresses));
        } catch (\Throwable) {
            throw new V2ContentContactException('IMAGE_FETCH_FAILED', 422, '画像を取得できませんでした。');
        }
    }
}

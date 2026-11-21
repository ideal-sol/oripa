<?php

namespace App\Domain\Line\Services;

use Illuminate\Support\Facades\Storage;

class V2LineMediaStorage
{
    public function upload(array $file): array
    {
        $client = $this->client();
        $command = $client->getCommand('PutObject', ['Bucket' => config('filesystems.disks.s3.bucket'), 'Key' => $file['key'],
            'ContentType' => $file['mime'], 'ContentLength' => $file['bytes'], 'ChecksumSHA256' => base64_encode(hex2bin($file['sha256'])), 'IfNoneMatch' => '*']);
        // The SDK normally excludes these browser headers from SigV4. This
        // dedicated signer keeps their exact values cryptographically bound.
        $signer = new class('s3', $client->getRegion()) extends \Aws\Signature\S3SignatureV4 {
            protected function getHeaderBlacklist(): array
            {
                return array_diff_key(parent::getHeaderBlacklist(), array_flip(['content-type','content-length','if-none-match']));
            }
        };
        $command->getHandlerList()->remove('signer');
        $command->getHandlerList()->remove('s3.checksum');
        $request = $signer->presign(\Aws\serialize($command), $client->getCredentials()->wait(), '+5 minutes');
        $headers = [];
        foreach ($request->getHeaders() as $key => $values) if (strtolower($key) !== 'host') $headers[$key] = implode(', ', $values);
        return ['url' => (string) $request->getUri(), 'headers' => $headers];
    }

    public function download(array $file, string $path): void
    {
        $client = $this->client(); $args = ['Bucket' => config('filesystems.disks.s3.bucket'), 'Key' => $file['key']];
        $head = $client->headObject($args);
        if ((int) $head['ContentLength'] !== $file['bytes'] || $head['ContentType'] !== $file['mime']) throw new \InvalidArgumentException('LINE_MEDIA_INVALID');
        $result = $client->getObject($args); $body = $result['Body']; $out = fopen($path, 'xb'); $size = 0;
        try {
            while (! $body->eof()) {
                $part = $body->read(65536); $size += strlen($part);
                if ($size > $file['bytes']) throw new \InvalidArgumentException('LINE_MEDIA_INVALID');
                if (fwrite($out, $part) !== strlen($part)) throw new \RuntimeException('LINE_MEDIA_UNAVAILABLE');
            }
        } finally { fclose($out); $body->close(); }
        if ($size !== $file['bytes'] || ! hash_equals($file['sha256'], hash_file('sha256', $path))) throw new \InvalidArgumentException('LINE_MEDIA_INVALID');
    }

    public function publish(string $key, string $path, string $mime): void
    {
        $body = fopen($path, 'rb');
        try {
            $this->client()->putObject(['Bucket' => config('filesystems.disks.s3.bucket'), 'Key' => $key, 'Body' => $body,
                'ContentType' => $mime, 'ContentLength' => filesize($path), 'ChecksumSHA256' => base64_encode(hash_file('sha256', $path, true)),
                'IfNoneMatch' => '*', 'CacheControl' => 'public, max-age=31536000, immutable']);
        } finally { if (is_resource($body)) fclose($body); }
    }

    private function client(): \Aws\S3\S3Client
    {
        if (config('filesystems.default') !== 's3' || ! config('filesystems.disks.s3.bucket')) throw new \RuntimeException('LINE_MEDIA_UNAVAILABLE');
        return Storage::disk('s3')->getClient();
    }
}

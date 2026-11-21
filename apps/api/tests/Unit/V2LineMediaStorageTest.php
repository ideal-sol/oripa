<?php

namespace Tests\Unit;

use App\Domain\Line\Services\V2LineMediaStorage;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class V2LineMediaStorageTest extends TestCase
{
    public function test_publish_survives_sdk_stream_ownership_for_original_and_preview(): void
    {
        config(['filesystems.default'=>'s3','filesystems.disks.s3.bucket'=>'synthetic-only']);
        $calls = 0;
        $client = new S3Client(['version'=>'latest', 'region'=>'us-east-1',
            'credentials'=>['key'=>'synthetic','secret'=>'synthetic-only'],
            'http_handler'=>function ($request) use (&$calls) {
                self::assertSame('abc', (string) $request->getBody());
                $request->getBody()->close();
                $calls++;
                return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200));
            }]);
        Storage::shouldReceive('disk')->with('s3')->twice()->andReturn(new class($client) {
            public function __construct(private S3Client $client) {} public function getClient(): S3Client { return $this->client; }
        });
        $path = tempnam(sys_get_temp_dir(), 'lineux-');
        file_put_contents($path, 'abc');
        try {
            foreach (['original','preview'] as $slot) (new V2LineMediaStorage)->publish('synthetic/'.$slot, $path, 'image/png');
            self::assertSame(2, $calls);
        } finally { unlink($path); }
    }

    public function test_publish_does_not_hide_a_failed_put_after_the_sdk_closes_the_stream(): void
    {
        config(['filesystems.default'=>'s3','filesystems.disks.s3.bucket'=>'synthetic-only']);
        $client = new S3Client(['version'=>'latest', 'region'=>'us-east-1', 'retries'=>0,
            'credentials'=>['key'=>'synthetic','secret'=>'synthetic-only'],
            'http_handler'=>function ($request) {
                $request->getBody()->close();
                return \GuzzleHttp\Promise\Create::rejectionFor(new \GuzzleHttp\Exception\RequestException('Synthetic rejection', $request,
                    new \GuzzleHttp\Psr7\Response(403, [], '<Error><Code>AccessDenied</Code></Error>')));
            }]);
        Storage::shouldReceive('disk')->with('s3')->once()->andReturn(new class($client) {
            public function __construct(private S3Client $client) {} public function getClient(): S3Client { return $this->client; }
        });
        $path = tempnam(sys_get_temp_dir(), 'lineux-'); file_put_contents($path, 'abc');
        try {
            $this->expectException(\GuzzleHttp\Exception\RequestException::class);
            $this->expectExceptionMessage('Synthetic rejection');
            (new V2LineMediaStorage)->publish('synthetic/original', $path, 'image/png');
        } finally { unlink($path); }
    }

    public function test_signed_upload_binds_checksum_mime_length_and_conditional_key(): void
    {
        config(['filesystems.default'=>'s3','filesystems.disks.s3.bucket'=>'synthetic-only']);
        $client=new S3Client(['version'=>'latest','region'=>'us-east-1','credentials'=>['key'=>'synthetic','secret'=>'synthetic-only']]);
        Storage::shouldReceive('disk')->with('s3')->once()->andReturn(new class($client) {
            public function __construct(private S3Client $client) {} public function getClient(): S3Client { return $this->client; }
        });
        $file=['key'=>'line-upload-private/00000000-0000-4000-8000-000000000001/original','mime'=>'image/png','bytes'=>3,'sha256'=>hash('sha256','abc')];
        $result=(new V2LineMediaStorage)->upload($file);
        parse_str(parse_url($result['url'],PHP_URL_QUERY),$query);
        self::assertSame('300',$query['X-Amz-Expires']);
        self::assertStringContainsString('if-none-match',$query['X-Amz-SignedHeaders']);
        self::assertStringContainsString('content-length',$query['X-Amz-SignedHeaders']);
        self::assertStringContainsString('content-type',$query['X-Amz-SignedHeaders']);
        self::assertStringContainsString('/'.$file['key'],parse_url($result['url'],PHP_URL_PATH));
        self::assertSame('*',array_change_key_case($result['headers'])['if-none-match']);
    }

    public function test_actual_download_rejects_mime_size_hash_and_overlong_stream(): void
    {
        config(['filesystems.default'=>'s3','filesystems.disks.s3.bucket'=>'synthetic-only']);
        $file=['key'=>'synthetic','mime'=>'image/png','bytes'=>3,'sha256'=>hash('sha256','abc')];
        foreach ([[3,'image/png','abc',true],[3,'text/plain','abc',false],[4,'image/png','abcd',false],
            [3,'image/png','bad',false],[3,'image/png','abcd',false],[3,'image/png','ab',false]] as [$length,$mime,$bytes,$valid]) {
            $handler=new MockHandler([new Result(['ContentLength'=>$length,'ContentType'=>$mime]),new Result(['Body'=>Utils::streamFor($bytes)])]);
            $client=new S3Client(['version'=>'latest','region'=>'us-east-1','credentials'=>['key'=>'synthetic','secret'=>'synthetic-only'],'handler'=>$handler]);
            Storage::shouldReceive('disk')->with('s3')->once()->andReturn(new class($client) {
                public function __construct(private S3Client $client) {} public function getClient(): S3Client { return $this->client; }
            });
            $path=sys_get_temp_dir().'/d5-storage-'.bin2hex(random_bytes(8));
            try {
                (new V2LineMediaStorage)->download($file,$path);
                self::assertTrue($valid,'Invalid object accepted'); self::assertSame('abc',file_get_contents($path));
            } catch (\InvalidArgumentException $e) { self::assertFalse($valid); self::assertSame('LINE_MEDIA_INVALID',$e->getMessage()); }
            finally { if(is_file($path)) unlink($path); }
        }
    }
}

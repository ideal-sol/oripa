<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\HealthController;
use App\Providers\AppServiceProvider;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HealthControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Container $container;

    private string $storageRoot;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/oripa-health-'.bin2hex(random_bytes(12));
        $this->container = new Container();
        Container::setInstance($this->container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        DB::swap(Mockery::mock());
        Redis::swap(Mockery::mock());
        $this->container->instance('config', new Repository([
            'filesystems' => [
                'default' => 'local',
                'disks' => ['local' => ['driver' => 'local', 'root' => $this->storageRoot, 'throw' => true]],
            ],
            'v2_identity' => ['sms_verification' => ['ttl_minutes' => 10]],
        ]));
        $this->container->instance('date', new DateFactory());
        $this->container->instance('filesystem', new FilesystemManager($this->container));
        $this->container->instance(ResponseFactoryContract::class, new ResponseFactory(
            Mockery::mock(ViewFactory::class), Mockery::mock(Redirector::class)
        ));
        (new AppServiceProvider($this->container))->boot();
    }

    protected function tearDown(): void
    {
        if (is_file($this->storageRoot.'/.healthcheck')) {
            unlink($this->storageRoot.'/.healthcheck');
        }
        if (is_dir($this->storageRoot)) {
            rmdir($this->storageRoot);
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function healthyDependencies(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([]);
        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andReturn(true);
    }

    private function s3(array $responses): void
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($this->requests));
        config([
            'filesystems.default' => 'asset-store',
            'filesystems.disks.asset-store' => [
                'driver' => 's3', 'region' => 'ap-northeast-1', 'bucket' => 'health-unit-test',
                'credentials' => false, 'retries' => 0, 'throw' => true,
                'http' => ['connect_timeout' => 5, 'timeout' => 20, 'read_timeout' => 20],
                'http_handler' => new GuzzleHandler(new Client(['handler' => $handler])),
            ],
        ]);
    }

    private function assertSingleReadOnlyList(): void
    {
        self::assertCount(1, $this->requests);
        $request = $this->requests[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('health-unit-test.s3.ap-northeast-1.amazonaws.com', $request->getUri()->getHost());
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame('2', $query['list-type']);
        self::assertSame('admin-assets/', $query['prefix']);
        self::assertSame('1', $query['max-keys']);
        self::assertArrayNotHasKey('continuation-token', $query);
        self::assertFalse($request->hasHeader('x-amz-acl'));
        self::assertSame('', (string) $request->getBody());
        self::assertSame(5, $this->requests[0]['options']['connect_timeout']);
        self::assertSame(20, $this->requests[0]['options']['timeout']);
        self::assertSame(20, $this->requests[0]['options']['read_timeout']);
    }

    public static function successfulListings(): array
    {
        return [
            'empty prefix' => ['<KeyCount>0</KeyCount><IsTruncated>false</IsTruncated>'],
            'existing objects' => ['<KeyCount>1</KeyCount><IsTruncated>true</IsTruncated><NextContinuationToken>not-followed</NextContinuationToken><Contents><Key>admin-assets/gacha/private-object.png</Key><Size>68</Size></Contents>'],
        ];
    }

    #[DataProvider('successfulListings')]
    public function test_s3_health_uses_one_bounded_read_only_request(string $contents): void
    {
        $this->healthyDependencies();
        $this->s3([new Response(200, ['Content-Type' => 'application/xml'], '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'.$contents.'</ListBucketResult>')]);
        $response = (new HealthController())();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['app' => 'ok', 'db' => 'ok', 'redis' => 'ok', 'storage' => 'ok'], array_diff_key($response->getData(true), ['timestamp' => true]));
        self::assertStringNotContainsString('private-object', $response->getContent());
        $this->assertSingleReadOnlyList();
        self::assertFileDoesNotExist($this->storageRoot.'/.healthcheck');
    }

    public static function rejectedListings(): array
    {
        return ['denied' => [403, 'AccessDenied'], 'credentials' => [403, 'InvalidAccessKeyId'], 'missing bucket' => [404, 'NoSuchBucket'], 'unavailable' => [503, 'ServiceUnavailable']];
    }

    #[DataProvider('rejectedListings')]
    public function test_s3_failures_return_sanitized_503_without_retry_or_write(int $status, string $code): void
    {
        $this->healthyDependencies();
        $this->s3([new Response($status, ['Content-Type' => 'application/xml'], '<Error><Code>'.$code.'</Code><Message>synthetic-private-detail</Message></Error>')]);
        $response = (new HealthController())();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('error', $response->getData(true)['storage']);
        self::assertStringNotContainsString('synthetic-private-detail', $response->getContent());
        self::assertStringNotContainsString($code, $response->getContent());
        $this->assertSingleReadOnlyList();
    }

    public function test_s3_connection_timeout_fails_closed(): void
    {
        $this->healthyDependencies();
        $this->s3([new ConnectException('synthetic timeout', new Request('GET', 'https://example.test'))]);
        $response = (new HealthController())();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('error', $response->getData(true)['storage']);
        $this->assertSingleReadOnlyList();
    }

    public function test_invalid_s3_configuration_fails_without_network(): void
    {
        $this->healthyDependencies();
        $this->s3([]);
        config(['filesystems.disks.asset-store.bucket' => '']);
        self::assertSame(503, (new HealthController())()->getStatusCode());
        self::assertSame([], $this->requests);
    }

    public function test_local_storage_keeps_the_existing_health_file(): void
    {
        $this->healthyDependencies();
        $response = (new HealthController())();
        self::assertSame(200, $response->getStatusCode());
        self::assertFileExists($this->storageRoot.'/.healthcheck');
        self::assertNotEmpty(file_get_contents($this->storageRoot.'/.healthcheck'));
        self::assertSame([], $this->requests);
    }

    public function test_failed_credential_provider_is_not_reported_healthy(): void
    {
        $this->healthyDependencies();
        $this->s3([]);
        config(['filesystems.disks.asset-store.credentials' => static function () {
            throw new RuntimeException('synthetic credential provider unavailable');
        }]);
        $response = (new HealthController())();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('error', $response->getData(true)['storage']);
        self::assertSame([], $this->requests);
        self::assertStringNotContainsString('credential provider', $response->getContent());
    }

    public function test_local_storage_exception_remains_unhealthy(): void
    {
        $this->healthyDependencies();
        Storage::shouldReceive('disk')->once()->with('local')->andThrow(new RuntimeException('synthetic local failure'));
        self::assertSame(503, (new HealthController())()->getStatusCode());
    }

    public function test_database_failure_is_not_hidden_by_successful_s3(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andThrow(new RuntimeException('synthetic DB unavailable'));
        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andReturn(true);
        $this->s3([new Response(200, [], '<ListBucketResult><KeyCount>0</KeyCount></ListBucketResult>')]);
        $response = (new HealthController())();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('error', $response->getData(true)['db']);
        self::assertSame('ok', $response->getData(true)['storage']);
        $this->assertSingleReadOnlyList();
    }

    public function test_redis_failure_is_not_hidden_by_successful_s3(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([]);
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('synthetic Redis unavailable'));
        $this->s3([new Response(200, [], '<ListBucketResult><KeyCount>0</KeyCount></ListBucketResult>')]);
        $response = (new HealthController())();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('error', $response->getData(true)['redis']);
        self::assertSame('ok', $response->getData(true)['storage']);
        $this->assertSingleReadOnlyList();
    }
}

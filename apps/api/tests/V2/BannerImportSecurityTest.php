<?php

namespace Tests\V2;

use App\Domain\ContentContact\Contracts\V2BannerImportResolver;
use App\Domain\ContentContact\Contracts\V2BannerImportTransport;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2BannerImportImageFetcher;
use App\Domain\ContentContact\Services\V2BannerImportUrlPolicy;
use Tests\TestCase;

final class BannerImportSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['v2_banner_import.enabled' => true]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedAddresses')]
    public function test_non_global_addresses_are_rejected(string $address): void
    {
        $this->expectException(V2ContentContactException::class);
        app(V2BannerImportUrlPolicy::class)->assertPublicIp($address);
    }

    public static function rejectedAddresses(): array
    {
        return array_map(static fn (string $address): array => [$address], ['0.0.0.0', '10.1.2.3', '127.0.0.1',
            '100.64.0.1', '100.127.255.255', '169.254.169.254', '172.16.0.1', '172.31.255.255',
            '192.168.1.1', '192.0.0.1', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '255.255.255.255', '::', '::1', '::ffff:127.0.0.1', 'fc00::1', 'fd00::1', 'fe80::1',
            'ff00::1', '2001:db8::1', '2002:7f00:1::1', '64:ff9b::7f00:1', '2001::1', '3fff::1', 'not-an-ip']);
    }

    public function test_url_syntax_restrictions_remain_without_host_allowlist(): void
    {
        $policy = app(V2BannerImportUrlPolicy::class);
        foreach (['http://images.example.test/a', 'https://images.example.test:444/a',
            'https://user:pass@images.example.test/a', 'https://images.example.test/a#fragment',
            'https://bad_host.example.test/a', 'https://127.0.0.1/a', 'https://[::1]/a',
            'https://93.184.215.14/a', 'https://[2606:4700:4700::1111]/a',
            'https://-invalid.example.test/a', 'https://images..example.test/a',
            'https://images.example.test./a', "https://images.example.test/\r\na", 'file:///tmp/image'] as $url) {
            try { $policy->validate($url); self::fail('URL accepted'); }
            catch (V2ContentContactException $exception) { self::assertStringNotContainsString('example.test', $exception->getMessage()); }
        }
        self::assertSame('images.example.test', $policy->validate('https://images.example.test:443/a?signature=synthetic'));
    }

    public function test_public_domains_are_fetched_independently_of_missing_or_stale_allowlist(): void
    {
        self::assertArrayNotHasKey('image_hosts', config('v2_banner_import'));
        foreach ([null, [], ['old.example.test'], 'invalid-legacy-value'] as $legacy) {
            config(['v2_banner_import.image_hosts' => $legacy]);
            foreach (['images.example.test', 'other.example.test', 'images.example.test.evil.test'] as $host) {
                $resolver = $this->createMock(V2BannerImportResolver::class);
                $resolver->expects(self::once())->method('resolve')->with($host)->willReturn(['93.184.215.14']);
                $transport = $this->createMock(V2BannerImportTransport::class);
                $transport->expects(self::once())->method('fetch')->with('https://'.$host.'/a', $host,
                    '93.184.215.14', self::callback(static fn (int $timeout): bool => $timeout > 0 && $timeout <= 20000))
                    ->willReturn("GIF89a\x01\0\x01\0\x80\0\0\0\0\0\xff\xff\xff");
                $image = (new V2BannerImportImageFetcher(new V2BannerImportUrlPolicy(), $resolver, $transport))
                    ->fetch('https://'.$host.'/a');
                self::assertSame('image/gif', $image['mime_type']);
            }
        }
    }

    public function test_legacy_environment_is_ignored_by_configuration(): void
    {
        $previous = getenv('V2_BANNER_IMPORT_IMAGE_HOSTS');
        try {
            foreach ([false, 'old.example.test'] as $value) {
                putenv($value === false ? 'V2_BANNER_IMPORT_IMAGE_HOSTS' : 'V2_BANNER_IMPORT_IMAGE_HOSTS='.$value);
                $settings = require base_path('config/v2_banner_import.php');
                self::assertArrayNotHasKey('image_hosts', $settings);
                config(['v2_banner_import' => $settings]);
                self::assertSame('unlisted.example.test', app(V2BannerImportUrlPolicy::class)->validate('https://unlisted.example.test/a'));
            }
        } finally {
            putenv($previous === false ? 'V2_BANNER_IMPORT_IMAGE_HOSTS' : 'V2_BANNER_IMPORT_IMAGE_HOSTS='.$previous);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedAddresses')]
    public function test_unlisted_host_with_any_non_global_dns_answer_never_transports(string $address): void
    {
        $resolver = $this->createMock(V2BannerImportResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('unlisted.example.test')->willReturn(['93.184.215.14', $address]);
        $transport = $this->createMock(V2BannerImportTransport::class);
        $transport->expects(self::never())->method('fetch');
        $this->expectException(V2ContentContactException::class);
        (new V2BannerImportImageFetcher(new V2BannerImportUrlPolicy(), $resolver, $transport))->fetch('https://unlisted.example.test/a');
    }

    public function test_localhost_is_rejected_by_dns_before_transport(): void
    {
        $resolver = $this->createMock(V2BannerImportResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('localhost')->willReturn(['127.0.0.1', '::1']);
        $transport = $this->createMock(V2BannerImportTransport::class);
        $transport->expects(self::never())->method('fetch');
        $this->expectException(V2ContentContactException::class);
        (new V2BannerImportImageFetcher(new V2BannerImportUrlPolicy(), $resolver, $transport))->fetch('https://localhost/a');
    }

    public function test_all_dns_answers_are_checked_before_transport(): void
    {
        $resolver = $this->createMock(V2BannerImportResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(['93.184.215.14', 'fd00::1']);
        $transport = $this->createMock(V2BannerImportTransport::class);
        $transport->expects(self::never())->method('fetch');
        $fetcher = new V2BannerImportImageFetcher(new V2BannerImportUrlPolicy(), $resolver, $transport);
        $this->expectException(V2ContentContactException::class);
        $fetcher->fetch('https://images.example.test/a');
    }

    public function test_resolved_ip_is_pinned_without_second_resolution(): void
    {
        $resolver = $this->createMock(V2BannerImportResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(['93.184.215.14']);
        $transport = $this->createMock(V2BannerImportTransport::class);
        $transport->expects(self::once())->method('fetch')->with('https://images.example.test/a', 'images.example.test',
            '93.184.215.14', self::callback(static fn (int $timeout): bool => $timeout > 0 && $timeout <= 20000))
            ->willReturn("GIF89a\x01\0\x01\0\x80\0\0\0\0\0\xff\xff\xff");
        $image = (new V2BannerImportImageFetcher(new V2BannerImportUrlPolicy(), $resolver, $transport))->fetch('https://images.example.test/a');
        self::assertSame('image/gif', $image['mime_type']);
        self::assertSame(hash('sha256', $image['bytes']), $image['checksum_sha256']);
    }

    public function test_size_mime_and_exception_redaction(): void
    {
        foreach ([str_repeat('a', 5242881), '<svg onload="alert(1)"></svg>', new \RuntimeException('https://images.example.test/a?signature=synthetic')] as $payload) {
            $resolver = $this->createStub(V2BannerImportResolver::class);
            $resolver->method('resolve')->willReturn(['93.184.215.14']);
            $transport = $this->createStub(V2BannerImportTransport::class);
            if ($payload instanceof \Throwable) $transport->method('fetch')->willThrowException($payload);
            else $transport->method('fetch')->willReturn($payload);
            try {
                (new V2BannerImportImageFetcher(new V2BannerImportUrlPolicy(), $resolver, $transport))->fetch('https://images.example.test/a');
                self::fail('Unsafe image accepted');
            } catch (V2ContentContactException $exception) {
                self::assertStringNotContainsString('example.test', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public function test_disabled_fetcher_never_resolves_or_transports(): void
    {
        config(['v2_banner_import.enabled' => false]);
        $resolver = $this->createMock(V2BannerImportResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $transport = $this->createMock(V2BannerImportTransport::class);
        $transport->expects(self::never())->method('fetch');
        $this->expectException(V2ContentContactException::class);
        (new V2BannerImportImageFetcher(new V2BannerImportUrlPolicy(), $resolver, $transport))->fetch('https://images.example.test/a');
    }

    public function test_native_transport_security_options_streaming_and_redirect_rejection_offline(): void
    {
        $transport = new class extends V2BannerImportTransport {
            public array $observed = [];
            public int $status = 200;
            public string $address = '93.184.215.14';
            public string $body = 'synthetic';
            public string $encoding = 'identity';
            public int $written = -1;
            public bool $success = true;
            protected function transfer(#[\SensitiveParameter] array $options): array
            {
                $this->observed = $options;
                $options[CURLOPT_HEADERFUNCTION](null, 'Content-Encoding: '.$this->encoding);
                $this->written = $options[CURLOPT_WRITEFUNCTION](null, $this->body);
                return ['success' => $this->success, 'status' => $this->status, 'address' => $this->address];
            }
        };
        self::assertSame('synthetic', $transport->fetch('https://images.example.test/a', 'images.example.test', '93.184.215.14', 19000));
        $options = $transport->observed;
        self::assertSame(['images.example.test:443:93.184.215.14'], $options[CURLOPT_RESOLVE]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(5000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(19000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(['Accept: image/gif, image/jpeg, image/png, image/webp', 'Accept-Encoding: identity'], $options[CURLOPT_HTTPHEADER]);
        foreach ([[302, '93.184.215.14', 'identity', 'body'], [200, '127.0.0.1', 'identity', 'body'],
            [200, '93.184.215.14', 'gzip', 'body'], [200, '93.184.215.14', 'identity', str_repeat('x', 5242881)]] as $case) {
            [$transport->status, $transport->address, $transport->encoding, $transport->body] = $case;
            try { $transport->fetch('https://images.example.test/a', 'images.example.test', '93.184.215.14', 20000); self::fail('Transport accepted'); }
            catch (V2ContentContactException $exception) { self::assertContains($exception->errorCode, ['IMAGE_FETCH_FAILED', 'IMAGE_TOO_LARGE']); }
        }
        self::assertSame(0, $transport->written);
        $transport->body = 'synthetic';
        $transport->address = '2606:4700:4700::1111';
        self::assertSame('synthetic', $transport->fetch('https://unlisted.example.test/a', 'unlisted.example.test', $transport->address, 1000));
        self::assertSame(['unlisted.example.test:443:[2606:4700:4700::1111]'], $transport->observed[CURLOPT_RESOLVE]);
        self::assertSame(1000, $transport->observed[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(1000, $transport->observed[CURLOPT_TIMEOUT_MS]);
        $transport->success = false;
        $this->expectException(V2ContentContactException::class);
        $transport->fetch('https://unlisted.example.test/a', 'unlisted.example.test', $transport->address, 20000);
    }
}

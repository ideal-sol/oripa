<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TrustedProxyTest extends TestCase
{
    public function test_bootstrapped_environment_corrects_tls_terminated_callback(): void
    {
        $env = Env::getRepository();
        $before = $env->get('V2_TRUSTED_PROXY_IPS');
        $env->set('V2_TRUSTED_PROXY_IPS', '192.0.2.10');
        try {
            $this->refreshApplication();
            $r = Request::create('http://storefront.example.test/api/v2/auth/external/line/callback', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https']);
            self::assertSame('https://storefront.example.test/api/v2/auth/external/line/callback', $this->throughGlobalMiddleware($r)[0]);
        } finally {
            $before === null ? $env->clear('V2_TRUSTED_PROXY_IPS') : $env->set('V2_TRUSTED_PROXY_IPS', $before);
        }
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);
        parent::tearDown();
    }

    private function throughGlobalMiddleware(Request $request): array
    {
        $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();
        self::assertContains(TrustProxies::class, $middleware);
        return (new Pipeline($this->app))->send($request)->through($middleware)
            ->then(fn (Request $r) => response()->json([$r->url(), $r->ip(), $r->getPort()]))->getData(true);
    }

    #[DataProvider('proxyCases')]
    public function test_only_explicit_proxy_can_supply_scheme(array $proxies, string $remote, ?string $proto, string $scheme): void
    {
        config(['trustedproxy.proxies' => $proxies]);
        $request = Request::create('http://storefront.example.test/api/v2/auth/external/line/callback', 'GET', [], [], [], ['REMOTE_ADDR' => $remote]);
        if ($proto !== null) $request->headers->set('X-Forwarded-Proto', $proto);
        [$url] = $this->throughGlobalMiddleware($request);
        self::assertSame($scheme.'://storefront.example.test/api/v2/auth/external/line/callback', $url);
    }

    public static function proxyCases(): array
    {
        return [
            'trusted HTTPS' => [['192.0.2.10'], '192.0.2.10', 'https', 'https'],
            'untrusted spoof' => [['192.0.2.10'], '192.0.2.11', 'https', 'http'],
            'no configured trust' => [[], '192.0.2.10', 'https', 'http'],
            'trusted HTTP' => [['192.0.2.10'], '192.0.2.10', 'http', 'http'],
            'missing header' => [['192.0.2.10'], '192.0.2.10', null, 'http'],
            'explicit IPv6' => [['2001:db8::10'], '2001:db8::10', 'https', 'https'],
        ];
    }

    public function test_other_forwarding_headers_cannot_change_host_path_port_or_client_ip(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $r = Request::create('http://storefront.example.test/api/v2/auth/external/google/callback', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']);
        foreach (['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example.test',
            'X-Forwarded-Port' => '8443', 'X-Forwarded-Prefix' => '/attacker',
            'X-Forwarded-For' => '198.51.100.99', 'Forwarded' => 'for=198.51.100.99;host=attacker.example.test;proto=http'] as $key => $value) {
            $r->headers->set($key, $value);
        }
        self::assertSame(['https://storefront.example.test/api/v2/auth/external/google/callback', '192.0.2.10', 443], $this->throughGlobalMiddleware($r));
    }

    public function test_native_https_is_preserved_without_proxy_trust(): void
    {
        config(['trustedproxy.proxies' => []]);
        $r = Request::create('https://storefront.example.test/api/v2/auth/external/line/callback');
        $r->headers->set('X-Forwarded-Proto', 'http');
        self::assertSame('https://storefront.example.test/api/v2/auth/external/line/callback', $this->throughGlobalMiddleware($r)[0]);
    }

    #[DataProvider('invalidLists')]
    public function test_invalid_or_unbounded_configuration_fails_closed(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->readConfiguration($value);
    }

    public static function invalidLists(): array
    {
        return array_map(fn ($value) => [$value], ['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0', '192.0.2.0/24', 'proxy.example.test', '192.0.2.10,', '192.0.2.10,invalid']);
    }

    public function test_configuration_accepts_only_explicit_ips_and_defaults_to_no_trust(): void
    {
        self::assertSame([], $this->readConfiguration('')['proxies']);
        self::assertSame(['192.0.2.10', '2001:db8::10'], $this->readConfiguration(' 192.0.2.10, 2001:db8::10 ')['proxies']);
    }

    private function readConfiguration(string $value): array
    {
        $env = Env::getRepository();
        $before = $env->get('V2_TRUSTED_PROXY_IPS');
        $env->set('V2_TRUSTED_PROXY_IPS', $value);
        try {
            return require base_path('config/trustedproxy.php');
        } finally {
            $before === null ? $env->clear('V2_TRUSTED_PROXY_IPS') : $env->set('V2_TRUSTED_PROXY_IPS', $before);
        }
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_process_health_route_is_registered_once_and_responds_without_dependencies(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn (Route $route): bool => $route->uri() === 'up')
            ->values();

        self::assertCount(1, $routes);
        self::assertSame(['GET', 'HEAD'], $routes->first()->methods());
        self::assertSame([], $routes->first()->gatherMiddleware());

        $this->getJson('/up')
            ->assertOk()
            ->assertExactJson(['status' => 'up']);
    }

    public function test_deep_health_route_remains_registered(): void
    {
        $response = $this->getJson('/api/health');

        $this->assertContains($response->getStatusCode(), [200, 503]);
        $response->assertJsonStructure([
            'app',
            'db',
            'redis',
            'storage',
            'timestamp',
        ]);
    }

    public function test_custom_route_groups_keep_their_prefixes_and_api_middleware(): void
    {
        $routes = collect(app('router')->getRoutes());

        foreach ([
            'api/v2/content/banners',
            'admin/api/v2/auth/session',
            'webhooks/v2/line',
        ] as $uri) {
            $matchingRoutes = $routes
                ->filter(fn (Route $route): bool => $route->uri() === $uri)
                ->values();

            self::assertCount(1, $matchingRoutes, "Expected exactly one route for [{$uri}].");
            self::assertContains('api', $matchingRoutes->first()->gatherMiddleware());
        }
    }
}

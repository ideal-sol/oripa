<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_route_exists(): void
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
}

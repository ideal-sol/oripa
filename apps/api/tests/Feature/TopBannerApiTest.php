<?php

namespace Tests\Feature;

use App\Models\TopBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopBannerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_index_returns_active_top_banners_in_sort_order(): void
    {
        $second = TopBanner::factory()->create([
            'image_url' => 'https://example.test/second.png',
            'link_url' => '/gachas/2',
            'sort_order' => 20,
        ]);
        $first = TopBanner::factory()->create([
            'image_url' => 'https://example.test/first.png',
            'link_url' => '/gachas/1',
            'sort_order' => 10,
        ]);
        TopBanner::factory()->create([
            'image_url' => 'https://example.test/hidden.png',
            'link_url' => '/hidden',
            'sort_order' => 1,
            'is_active' => false,
        ]);

        $this->getJson('/api/top-banners')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.image_url', 'https://example.test/first.png')
            ->assertJsonPath('data.0.link_url', '/gachas/1')
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.image_url', 'https://example.test/second.png')
            ->assertJsonMissing(['image_url' => 'https://example.test/hidden.png']);
    }
}

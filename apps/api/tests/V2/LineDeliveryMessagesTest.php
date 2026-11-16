<?php

namespace Tests\V2;

use App\Domain\Line\Services\V2LineDeliveryMessages;
use App\Domain\Line\Services\V2LineDeliveryMedia;
use Tests\TestCase;

final class LineDeliveryMessagesTest extends TestCase
{
    public function test_five_shapes_order_layout_and_https_urls_are_preserved(): void
    {
        config(['v2_line.delivery_allowed_link_hosts' => ['arbitrary.example.test']]);
        $this->app->instance(V2LineDeliveryMedia::class, new class extends V2LineDeliveryMedia {
            public function assertVerified(#[\SensitiveParameter] array $message): void {}
        });
        $url = 'https://arbitrary.example.test/path?x=1&openExternalBrowser=0';
        $messages = [['type' => 'text', 'text' => '%username% %rank% %points% 50%オフ'],
            ['type' => 'image', 'originalContentUrl' => $url, 'previewImageUrl' => $url],
            ['type' => 'video', 'originalContentUrl' => $url, 'previewImageUrl' => $url],
            ['type' => 'imagemap', 'baseUrl' => $url, 'altText' => '%username%', 'height' => 1041, 'layout' => 'full', 'links' => [$url]],
            ['type' => 'image_carousel', 'altText' => '%rank%', 'columns' => [['imageUrl' => $url, 'uri' => $url, 'label' => 'open']]]];
        $service = app(V2LineDeliveryMessages::class);
        self::assertTrue($service->personalized($messages));
        $out = $service->render($messages, ['username' => '合成会員', 'rank' => '合成ランク', 'points' => 12345]);
        self::assertSame(['text', 'image', 'video', 'imagemap', 'template'], array_column($out, 'type'));
        self::assertSame('合成会員 合成ランク 12,345 50%オフ', $out[0]['text']);
        self::assertCount(1, $out[3]['actions']);
        self::assertSame($url, $out[3]['actions'][0]['linkUri']);
        self::assertSame(['x' => 0, 'y' => 0, 'width' => 1040, 'height' => 1041], $out[3]['actions'][0]['area']);
        self::assertSame($url, $out[4]['template']['columns'][0]['action']['uri']);
    }

    public function test_unicode_expansion_limits_and_defaults(): void
    {
        $s = app(V2LineDeliveryMessages::class);
        self::assertSame('お客様//0', $s->render([['type' => 'text', 'text' => '%username%/%rank%/%points%']])[0]['text']);
        $rendered = $s->render([['type' => 'text', 'text' => '%username%']], ['username' => str_repeat('😀', 2501)])[0]['text'];
        self::assertSame(2500, mb_strlen($rendered));
        self::assertTrue(mb_check_encoding($rendered));
        self::assertFalse($s->personalized([['type' => 'text', 'text' => '50% off']]));
    }

    public function test_unknown_tags_url_tags_and_message_count_are_rejected(): void
    {
        foreach ([[['type' => 'text', 'text' => '%missing%']], [['type' => 'text', 'text' => '%shipping_url%']],
            [['type' => 'image', 'originalContentUrl' => 'https://example.test/%username%', 'previewImageUrl' => 'https://example.test/a']],
            array_fill(0, 6, ['type' => 'text', 'text' => 'x']), [['type' => 'text', 'text' => str_repeat('😀', 2501)]],
            [['type' => 'video', 'originalContentUrl' => 'http://example.test/a', 'previewImageUrl' => 'https://example.test/a']]] as $messages) {
            try { app(V2LineDeliveryMessages::class)->validate($messages); self::fail('Invalid message accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Invalid LINE delivery message.', $e->getMessage()); }
        }
    }

    public function test_unverified_media_fails_closed_without_provider_or_download(): void
    {
        config(['v2_assets.public_base_url' => '']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        try {
            app(V2LineDeliveryMessages::class)->render([['type' => 'image', 'originalContentUrl' => 'https://example.test/a', 'previewImageUrl' => 'https://example.test/b']]);
            self::fail('Unconfigured media origin accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid Asset CDN origin.', $e->getMessage());
        }
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_split_imagemaps_and_unapproved_action_hosts_fail_closed(): void
    {
        config(['v2_line.delivery_allowed_link_hosts' => ['allowed.example.test']]);
        $base=['type'=>'imagemap','baseUrl'=>'https://cdn.example.test/image','altText'=>'Synthetic','height'=>1040,'layout'=>'full','links'=>['https://allowed.example.test/path']];
        foreach (['grid','horizontal','vertical'] as $layout) {
            try { app(V2LineDeliveryMessages::class)->validate([[...$base,'layout'=>$layout]]); self::fail('Split area accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Invalid LINE delivery message.',$e->getMessage()); }
        }
        foreach (['https://other.example.test/path','https://allowed.example.test:8443/path','https://user@allowed.example.test/path'] as $link) {
            try { app(V2LineDeliveryMessages::class)->validate([[...$base,'links'=>[$link]]]); self::fail('Unapproved action URL accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Invalid LINE delivery message.',$e->getMessage()); }
        }
        config(['v2_line.delivery_allowed_link_hosts'=>[]]);
        $this->expectException(\InvalidArgumentException::class);
        app(V2LineDeliveryMessages::class)->validate([$base]);
    }
}

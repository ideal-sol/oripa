<?php

namespace Tests\Feature;

use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\DTO\SmsMessage;
use App\Domain\Notification\Services\LogSmsSender;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SmsSenderTest extends TestCase
{
    public function test_log_sms_sender_can_be_resolved_from_container(): void
    {
        config(['services.sms.driver' => 'log']);

        $sender = $this->app->make(SmsSender::class);

        $this->assertInstanceOf(LogSmsSender::class, $sender);
    }

    public function test_log_sms_sender_records_sms_message_without_external_provider(): void
    {
        Log::spy();
        $sender = new LogSmsSender();

        $result = $sender->send(new SmsMessage(
            to: '09012345678',
            body: '認証コードは123456です。',
            userId: 10,
            purpose: 'registration',
            metadata: ['verification_id' => 99],
        ));

        $this->assertTrue($result->sent);
        $this->assertSame('log', $result->provider);
        $this->assertNotNull($result->messageId);
        Log::shouldHaveReceived('info')
            ->once()
            ->with('SMS notification logged.', \Mockery::on(fn (array $context): bool => $context['to'] === '09012345678'
                && $context['body'] === '認証コードは123456です。'
                && $context['user_id'] === 10
                && $context['purpose'] === 'registration'
                && $context['metadata']['verification_id'] === 99));
    }
}

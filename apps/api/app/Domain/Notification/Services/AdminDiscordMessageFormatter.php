<?php

namespace App\Domain\Notification\Services;

use App\Models\ContactRequest;
use App\Models\Payment;
use App\Models\ShippingRequest;

class AdminDiscordMessageFormatter
{
    public function contactReceived(ContactRequest $contactRequest): string
    {
        return implode("\n", [
            '【新規お問い合わせ】',
            sprintf('ID: %s', $contactRequest->id),
            sprintf('氏名: %s', $contactRequest->name),
            sprintf('メール: %s', $contactRequest->email),
            sprintf('電話番号: %s', $contactRequest->phone ?: '-'),
            sprintf('内容: %s', $this->truncate((string) $contactRequest->body, 500)),
        ]);
    }

    public function shippingRequested(ShippingRequest $shippingRequest): string
    {
        $shippingRequest->loadMissing('user', 'items.userPrize.gacha', 'items.userPrize.prize.rank');
        $prizeLines = $shippingRequest->items
            ->map(function ($item): string {
                $userPrize = $item->userPrize;
                $gachaTitle = $userPrize?->gacha?->title ?? '-';
                $rankName = $userPrize?->prize?->rank?->display_name ?? '-';
                $prizeName = $userPrize?->prize?->name ?? '-';

                return sprintf('- %s / %s / %s', $gachaTitle, $rankName, $prizeName);
            })
            ->values()
            ->all();

        return implode("\n", array_merge([
            '【新規配送申請】',
            sprintf('申請ID: %s', $shippingRequest->id),
            sprintf('ユーザー: %s <%s>', $shippingRequest->user?->name ?? '-', $shippingRequest->user?->email ?? '-'),
            sprintf('景品数: %s件', $shippingRequest->items->count()),
            sprintf('宛名: %s', $shippingRequest->recipient_name),
            sprintf('住所: 〒%s %s%s %s %s', $shippingRequest->postal_code, $shippingRequest->prefecture, $shippingRequest->city, $shippingRequest->address_line1, $shippingRequest->address_line2 ?? ''),
            sprintf('電話番号: %s', $shippingRequest->phone_number),
            '景品:',
        ], $prizeLines));
    }

    public function paymentSucceeded(Payment $payment): string
    {
        $payment->loadMissing('user');

        return implode("\n", [
            '【ポイント購入完了】',
            sprintf('決済ID: %s', $payment->id),
            sprintf('ユーザー: %s <%s>', $payment->user?->name ?? '-', $payment->user?->email ?? '-'),
            sprintf('金額: %s%s', number_format((int) $payment->amount), $payment->currency),
            sprintf('有償ポイント: %spt', number_format((int) $payment->paid_point_amount)),
            sprintf('無償ポイント: %spt', number_format((int) $payment->free_point_amount)),
            sprintf('プロバイダ: %s', $payment->provider),
            sprintf('プロバイダ決済ID: %s', $payment->provider_payment_id ?: '-'),
        ]);
    }

    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length).'...';
    }
}

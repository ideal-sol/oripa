{{ $user?->name ?? 'お客様' }} 様

いつもLuxe Packをご利用いただきありがとうございます。

本メールは重要なお知らせです。
対象の決済についてチャージバックが確認されたため、発送済みまたは配送済みの景品について返送のご相談をお願いしております。

対象情報:
- payment ID: #{{ $paymentReversal->payment_id }}
- reversal ID: #{{ $paymentReversal->id }}

返送依頼対象の景品:
@foreach ($actions as $action)
- {{ $action->userPrize?->prize?->name ?? '景品ID #'.$action->user_prize_id }}
  @if ($action->shipping_item_id)
  配送item ID: #{{ $action->shipping_item_id }}
  @endif
@endforeach

返送方法の詳細は、運営からの個別案内に従ってください。
返送期限、送料負担、その他の条件については確認後にご案内します。

ご不明点がある場合は、お問い合わせフォームよりご連絡ください。
{{ $frontendUrl }}/contact

本メールにお心当たりがない場合も、確認のためお問い合わせください。

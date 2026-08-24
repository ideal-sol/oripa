<?php

return [
    'templates' => [
        'email_verification' => '認証リンク送信時',
        'registration_completed' => '認証成功登録時',
        'coin_purchase_completed' => 'コイン購入完了時',
        'shipping_requested' => '発送依頼時',
        'shipping_completed' => '発送完了時',
        'user_closed' => '退会時',
        'contact_received' => 'お問い合わせ完了時',
    ],
    'variables' => [
        'user_name' => 'ユーザー名',
        'full_name' => '氏名',
        'address' => '住所',
        'phone_number' => '電話番号',
        'gacha_names' => 'ガチャ名',
        'prize_names' => '景品名',
        'purchase_plan' => 'コイン購入プラン',
        'purchase_amount' => '購入金額',
        'verification_url' => '認証リンク',
        'contact_body' => 'お問い合わせ内容',
    ],
    'preview_values' => [
        'user_name' => 'サンプルユーザー',
        'full_name' => '山田 太郎',
        'address' => '〒100-0001 東京都千代田区千代田1-1 サンプルビル101',
        'phone_number' => '090-1234-5678',
        'gacha_names' => ['春のガチャ', '夏のガチャ'],
        'prize_names' => ['景品A', '景品B', '景品C'],
        'purchase_plan' => 'サンプルコインプラン',
        'purchase_amount' => '1,000円',
        'verification_url' => 'https://example.test/verify/sample',
        'contact_body' => 'サンプルのお問い合わせ内容です。',
    ],
];

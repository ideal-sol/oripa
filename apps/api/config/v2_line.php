<?php

return [
    'messaging' => [
        'channel_secret' => env('LINE_MESSAGING_CHANNEL_SECRET'),
        'channel_access_token' => env('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'),
        'reply_endpoint' => 'https://api.line.me/v2/bot/message/reply',
        'login_relative_path' => '/login',
        'message_max_length' => 1000,
        'http_timeout_seconds' => 3,
        'reward_point_amount' => 0,
        'reward_expiration_days' => 180,
    ],
];

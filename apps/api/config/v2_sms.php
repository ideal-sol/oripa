<?php

return [
    'fours' => [
        'endpoint' => env('V2_SMS_FOURS_ENDPOINT', 'https://4sm.jp/api/sms_send'),
        'cp_userid' => env('V2_SMS_FOURS_CP_USERID'),
        'cp_password' => env('V2_SMS_FOURS_CP_PASSWORD'),
        'user_agent' => env('V2_SMS_FOURS_USER_AGENT', 'OripaV2-SMS/2'),
        'timeout_seconds' => (int) env('V2_SMS_FOURS_TIMEOUT_SECONDS', 10),
    ],
];

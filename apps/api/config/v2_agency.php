<?php

return [
    'login_url' => env('V2_AGENCY_LOGIN_URL', 'https://ad.luxe-pack.biz/'),
    'mailer' => env('V2_AGENCY_MAILER', env('MAIL_MAILER', 'array')),
];

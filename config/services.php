<?php

return [
    'payment_gateway' => [
        'webhook_secret' => env('PAYMENT_GATEWAY_WEBHOOK_SECRET', 'test-secret'),
    ],
];

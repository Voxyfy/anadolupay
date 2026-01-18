<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ödeme Geçidi Driver'ları
    |--------------------------------------------------------------------------
    |
    | Driver'ları kaydetmek için driver adını anahtar,
    | PaymentGatewayInterface implement eden sınıfı değer olarak ekleyin.
    |
    | Örnek:
    |
    | 'drivers' => [
    |     'iyzico' => \Voxyfy\AnadoluPay\Gateways\IyzicoGateway::class,
    | ],
    |
    */

    'drivers' => [

        // Geliştirme ve test için sahte gateway.
        // Gerçek API çağrısı yapmaz, rastgele başarı/başarısızlık simüle eder.
        'fake' => \Voxyfy\AnadoluPay\Gateways\FakeGateway::class,
        'iyzico' => \Voxyfy\AnadoluPay\Gateways\IyzicoGateway::class,

    ],

    'iyzico' => [
        'api_key' => env('IYZICO_API_KEY'),
        'secret_key' => env('IYZICO_SECRET_KEY'),
        // Test ortamı için sandbox URL; canlı kullanımda prod host'u kullanın.
        'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
        'callback_url' => env('IYZICO_CALLBACK_URL'),
    ],

];

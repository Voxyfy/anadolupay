<?php

use Voxyfy\AnadoluPay\Gateways\FakeGateway;
use Voxyfy\AnadoluPay\Gateways\IyzicoGateway;

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
        'fake' => FakeGateway::class,
        'iyzico' => IyzicoGateway::class,

    ],

    'iyzico' => [
        'api_key' => env('IYZICO_API_KEY'),
        'secret_key' => env('IYZICO_SECRET_KEY'),
        // Test ortamı için sandbox URL; canlı kullanımda prod host'u kullanın.
        'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
        'callback_url' => env('IYZICO_CALLBACK_URL'),
        // Canlı ortamda true kalsın; lokal testlerde false olabilir.
        'validate_signature' => env('IYZICO_VALIDATE_SIGNATURE', true),
        // Imza header ve parametre adlari saglayiciya gore degisebilir.
        'signature_header' => env('IYZICO_SIGNATURE_HEADER', 'x-iyzi-signature'),
        'signature_param' => env('IYZICO_SIGNATURE_PARAM', 'signature'),
    ],

];

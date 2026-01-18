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

    ],

];

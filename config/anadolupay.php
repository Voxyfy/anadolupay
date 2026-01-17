<?php

/**
 * AnadoluPay Yapılandırma Dosyası
 *
 * Bu dosya, AnadoluPay ödeme geçidi soyutlama paketinin ayarlarını içerir.
 * Birden fazla ödeme sağlayıcısını yapılandırabilir ve varsayılan
 * driver'ı belirleyebilirsiniz.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Varsayılan Ödeme Driver'ı
    |--------------------------------------------------------------------------
    |
    | Bu seçenek, belirli bir driver belirtilmediğinde kullanılacak varsayılan
    | ödeme geçidi driver'ını tanımlar. Aşağıdaki 'drivers' dizisindeki
    | anahtarlardan birini kullanabilirsiniz.
    |
    | null olarak ayarlandığında, ödeme işlemlerinde driver'ı açıkça
    | belirtmeniz gerekir.
    |
    | Desteklenen: Aşağıdaki 'drivers' dizisindeki herhangi bir anahtar
    |
    */

    'default' => env('ANADOLUPAY_DRIVER', null),

    /*
    |--------------------------------------------------------------------------
    | Ödeme Geçidi Driver'ları
    |--------------------------------------------------------------------------
    |
    | Burada uygulamanız için ödeme geçidi driver'larını yapılandırabilirsiniz.
    | Her driver, belirli bir ödeme sağlayıcısı entegrasyonunu temsil eder.
    |
    | Her driver yapılandırması şunları içermelidir:
    | - 'driver': PaymentGatewayInterface'i implement eden gateway sınıfı
    | - Sağlayıcıya özel kimlik bilgileri ve ayarlar
    |
    | Örnek yapılandırma:
    |
    | 'iyzico' => [
    |     'driver' => \Voxyfy\AnadoluPay\Gateways\IyzicoGateway::class,
    |     'api_key' => env('IYZICO_API_KEY'),
    |     'secret_key' => env('IYZICO_SECRET_KEY'),
    |     'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
    |     'sandbox' => env('IYZICO_SANDBOX', true),
    | ],
    |
    | 'paytr' => [
    |     'driver' => \Voxyfy\AnadoluPay\Gateways\PayTRGateway::class,
    |     'merchant_id' => env('PAYTR_MERCHANT_ID'),
    |     'merchant_key' => env('PAYTR_MERCHANT_KEY'),
    |     'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
    |     'sandbox' => env('PAYTR_SANDBOX', true),
    | ],
    |
    */

    'drivers' => [

        // Ödeme geçidi driver'ları burada yapılandırılacak.
        // Her driver PaymentGatewayInterface'i implement etmelidir.

    ],

];

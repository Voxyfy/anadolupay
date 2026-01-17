# AnadoluPay

[![Latest Version on Packagist](https://img.shields.io/packagist/v/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/voxyfy/anadolupay/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/voxyfy/anadolupay/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)

Laravel 12 için birleşik Türk ödeme geçidi soyutlama paketi. AnadoluPay, birden fazla Türk ödeme sağlayıcısını Laravel uygulamanıza entegre etmek için temiz ve tutarlı bir API sunar.

## Gereksinimler

- PHP 8.2 veya üzeri
- Laravel 12.x

## Kurulum

Paketi Composer ile yükleyin:

```bash
composer require voxyfy/anadolupay
```

Yapılandırma dosyasını yayınlayın:

```bash
php artisan vendor:publish --tag="anadolupay-config"
```

## Yapılandırma

Yayınladıktan sonra yapılandırma dosyası `config/anadolupay.php` konumunda olacaktır:

```php
return [
    // Varsayılan ödeme driver'ı
    'default' => env('ANADOLUPAY_DRIVER', null),

    // Ödeme geçidi driver'ları
    'drivers' => [
        // Ödeme sağlayıcılarınızı burada yapılandırın
    ],
];
```

Varsayılan driver'ı `.env` dosyanızda ayarlayın:

```env
ANADOLUPAY_DRIVER=iyzico
```

## Temel Kullanım

### Facade Kullanımı

```php
use Voxyfy\AnadoluPay\Facades\AnadoluPay;

// Varsayılan driver'ı al
$gateway = AnadoluPay::driver();

// Belirli bir driver'ı al
$gateway = AnadoluPay::driver('iyzico');

// Ödeme oluştur
$sonuc = AnadoluPay::driver()->createPayment([
    'amount' => 100.00,
    'currency' => 'TRY',
    'order_id' => 'SIPARIS-123',
    'customer' => [
        'name' => 'Ahmet Yılmaz',
        'email' => 'ahmet@example.com',
    ],
]);

// Ödemeyi doğrula
$dogrulama = AnadoluPay::driver()->verify($transactionId);

// İade işlemi yap
$iade = AnadoluPay::driver()->refund($transactionId, [
    'amount' => 50.00, // Kısmi iade
    'reason' => 'Müşteri talebi',
]);
```

### Dependency Injection Kullanımı

```php
use Voxyfy\AnadoluPay\AnadoluPay;

class OdemeController extends Controller
{
    public function __construct(
        protected AnadoluPay $anadoluPay
    ) {}

    public function odemeYap()
    {
        $gateway = $this->anadoluPay->driver();
        // ...
    }
}
```

### Mevcut Driver'ları Kontrol Etme

```php
// Tüm yapılandırılmış driver adlarını al
$drivers = AnadoluPay::getAvailableDrivers();

// Belirli bir driver'ın yapılandırılıp yapılandırılmadığını kontrol et
if (AnadoluPay::hasDriver('iyzico')) {
    // Driver mevcut
}
```

## Gateway Driver Oluşturma

Özel bir ödeme geçidi driver'ı oluşturmak için `PaymentGatewayInterface`'i implement edin:

```php
<?php

namespace App\PaymentGateways;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;

class OzelGateway implements PaymentGatewayInterface
{
    public function __construct(protected array $config)
    {
        // Yapılandırma ile başlat
    }

    public function createPayment(array $data): array
    {
        // Ödeme oluşturma mantığını implement et
    }

    public function verify(string $transactionId, array $data = []): array
    {
        // Ödeme doğrulama mantığını implement et
    }

    public function refund(string $transactionId, array $data = []): array
    {
        // İade mantığını implement et
    }
}
```

Ardından yapılandırmanıza kaydedin:

```php
'drivers' => [
    'ozel' => [
        'driver' => \App\PaymentGateways\OzelGateway::class,
        'api_key' => env('OZEL_GATEWAY_API_KEY'),
        'api_secret' => env('OZEL_GATEWAY_API_SECRET'),
        'sandbox' => env('OZEL_GATEWAY_SANDBOX', true),
    ],
],
```

## Desteklenen Sağlayıcılar (Planlanan)

Aşağıdaki Türk ödeme sağlayıcıları gelecek sürümler için planlanmaktadır:

- iyzico
- PayTR
- Param
- Sipay
- Craftgate
- Paynet
- Moka

## Hata Yönetimi

AnadoluPay, farklı hata senaryoları için özel exception'lar sağlar:

```php
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;

try {
    $sonuc = AnadoluPay::driver()->createPayment($veri);
} catch (DriverNotFoundException $e) {
    // Driver yapılandırılmamış
} catch (PaymentFailedException $e) {
    // Ödeme işleme başarısız
    $hataKodu = $e->getErrorCode();
    $kullaniciMesaji = $e->getUserMessage();
} catch (UnsupportedOperationException $e) {
    // İşlem bu gateway tarafından desteklenmiyor
}
```

## Testler

```bash
composer test
```

## Değişiklik Günlüğü

Son değişiklikler hakkında daha fazla bilgi için [CHANGELOG](CHANGELOG.md) dosyasına bakın.

## Katkıda Bulunma

Katkılarınızı bekliyoruz! Detaylar için [CONTRIBUTING](CONTRIBUTING.md) dosyasına bakın.

## Güvenlik Açıkları

Bir güvenlik açığı keşfederseniz, lütfen security@voxyfy.com adresine e-posta gönderin.

## Katkıda Bulunanlar

- [Voxyfy](https://github.com/Voxyfy)
- [Tüm Katkıda Bulunanlar](../../contributors)

## Lisans

MIT Lisansı (MIT). Daha fazla bilgi için [Lisans Dosyası](LICENSE.md)'na bakın.

# AnadoluPay

[![Latest Version on Packagist](https://img.shields.io/packagist/v/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/voxyfy/anadolupay/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/voxyfy/anadolupay/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)

AnadoluPay is a Laravel payment abstraction layer for Turkish payment providers.
It orchestrates payment flows and normalizes responses, while leaving UI rendering
and final business decisions to the consuming application.

## Gereksinimler

- PHP 8.2 veya üzeri
- Laravel 12.x

## Kurulum

```bash
composer require voxyfy/anadolupay
```

Laravel auto-discovery varsayılan olarak aktiftir; ek bir adım gerekmez.

Konfigürasyon dosyasını yayınlamak isterseniz:

```bash
php artisan vendor:publish --tag="anadolupay-config"
```

## Yapılandırma

Iyzico 3DS için gerekli ortam değişkenleri:

```env
IYZICO_API_KEY=xxx
IYZICO_SECRET_KEY=xxx
IYZICO_BASE_URL=https://sandbox-api.iyzipay.com
IYZICO_CALLBACK_URL=https://example.com/anadolupay/callback/iyzico
```

Notlar:
- `IYZICO_BASE_URL` sandbox veya production host olabilir.
- `IYZICO_CALLBACK_URL` dışarıdan erişilebilir olmalıdır.

## 3DS Ödeme Başlatma (iyzico)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\Facades\AnadoluPay;

class PaymentController extends Controller
{
    public function pay(Request $request)
    {
        $data = new CreatePaymentData(
            amount: 100.00,
            currency: 'TRY',
            orderId: 'SIPARIS-123',
            customer: [
                'name' => 'Ahmet Yilmaz',
                'email' => 'ahmet@example.com',
                'phone' => '+905551112233',
                'card' => [
                    'cardHolderName' => 'Ahmet Yilmaz',
                    'cardNumber' => '5528790000000008',
                    'expireYear' => '2030',
                    'expireMonth' => '12',
                    'cvc' => '123',
                ],
            ],
            successUrl: 'https://example.com/success',
            failUrl: 'https://example.com/fail',
        );

        $response = AnadoluPay::driver('iyzico')->createPayment($data);

        $threeDsHtml = $response->raw['threeDSHtmlContent'] ?? null;

        return response()->json([
            'threeDSHtmlContent' => $threeDsHtml,
        ]);
    }
}
```

## threeDSHtmlContent Render Etme

`threeDSHtmlContent` base64-encoded bir HTML dokümanıdır, bir URL değildir.
AnadoluPay bunu render etmez veya decode etmez; bu sorumluluk uygulamanızdadır.

```php
$response = AnadoluPay::driver('iyzico')->createPayment($data);

$html = base64_decode($response->raw['threeDSHtmlContent']);

return response($html);
```

## Callback ve Webhook Doğrulama

- Iyzico redirect callback'inde `status`, `paymentId`, `conversationData`, `mdStatus` alanları gelir.
- Gateway bu payload'u doğrular, ardından 3DS auth çağrısını yapar ve sonucu normalize eder.
- Webhook bildirimleri için de `verify(...)` çağrısı aynı şekilde çalışır.

AnadoluPay yalnızca doğrulama ve normalizasyon yapar. Sipariş onayı, stok düşme,
fatura kesme gibi iş kuralları uygulama tarafında yönetilmelidir.

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

## Lisans

MIT Lisansı (MIT). Daha fazla bilgi için [Lisans Dosyası](LICENSE.md)'na bakın.

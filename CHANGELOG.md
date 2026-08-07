# Changelog

All notable changes to `voxyfy/anadolupay` will be documented in this file.

## v1.0.3 - 2026-08-07

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.2...v1.0.3

## v1.0.2 - 2026-08-07

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.1...v1.0.2

## v1.0.1 - 2026-08-06

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.0...v1.0.1

## v1.0.0 - 2026-08-06

### What's Changed

* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/2
* Bump dependabot/fetch-metadata from 2.5.0 to 3.1.0 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/4

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v0.1.0...v1.0.0

## Unreleased

### Eklendi

* **`Money` value object.** Tutarlar artık paket içinde kuruş cinsinden tam
  sayı olarak taşınır; float aritmetiği ödeme yolundan çıkarıldı.
  `CreatePaymentData` ve `RefundPaymentData` hem `Money` hem `float` kabul
  eder, mevcut çağrılar değişmeden çalışır.
* **iyzico iadesi** — `/v2/payment/refund` ile tam ve kısmi iade.

### Düzeltildi — güvenlik

* **iyzico `Authorization` başlığı üç noktada birden yanlıştı**: imzalanan
  dizgi (`apiKey + rnd + gövde` yerine `randomKey + uriPath + gövde`),
  kodlama (base64 yerine hex) ve başlık biçimi. Ayrıca gövde imzalandıktan
  sonra HTTP istemcisi tarafından yeniden kodlanıyordu; artık imzalanan
  dizginin aynısı gönderiliyor.
* **iyzico yanıt ve callback imzası** doğrulanmamış bir alan sırası
  kullanıyordu. Resmi dokümantasyondaki sıralar uygulandı; her uç için ayrı
  doğrulama eklendi (initialize, 3DS auth, callback, refund).
* **iyzico webhook imzası** yanlış başlıktan (`x-iyzi-signature`) okunuyordu.
  Doğrusu `X-IYZ-SIGNATURE-V3`'tür ve HPP bildirimlerinde `token` da imzaya
  girer.

### Düzeltildi

* `IyzicoMapper` kart bilgisini yalnızca eski `customer['card']` dizisinden
  okuyordu; birinci sınıf `CardData` alanı yok sayılıyordu.

### Eklendi

* Türk bankaları için native sanal POS driver'ları: Asseco/Payten NestPay
  (Akbank, İş Bankası, Ziraat, Halkbank, QNB Finansbank, TEB, Şekerbank),
  Garanti BBVA, Yapı Kredi PosNet, Albaraka PosNet V1, VakıfBank/Ziraat PayFlex
  V4, DenizBank InterPos, QNB/Ziraat Katılım PayFor, Kuveyt Türk, Vakıf Katılım.
* Ödeme kuruluşu driver'ları: Akbank POS (yeni JSON API), PayTR, Param, Tosla.
* `CardData` DTO'su ve `PaymentResponse` üzerinde 3D Secure form alanları
  (`formAction`, `formFields`, `htmlContent`, `toHtmlForm()`).
* `config/anadolupay.php` içinde banka preset'leri; `AnadoluPay::driver()`
  artık banka anahtarlarını da çözümlüyor.
* Her altyapının imza algoritması için regresyon testleri.
* **PSR-3 loglama** (`anadolupay.logging`). Her banka isteği ve yanıtı, kart
  numarası maskelenip CVV/şifre gizlenerek kaydedilir. Maskeleme hem alan adına
  hem de değerin biçimine (Luhn doğrulamalı) göre çalışır. Varsayılan kapalıdır.

### Düzeltildi

* `Currency::alphabetic()` her iki dalı aynı olan bir koşul içeriyordu ve
  sıfırla doldurulmuş kodları (Kuveyt Türk `0949`) çözemiyordu.
* `AbstractBankGateway::paymentModelOf()` hiçbir yerde yazılmayan bir
  `anadolupay_payment_model` anahtarını okuyordu; kaldırıldı.

### Değişti

* Laravel 13 desteği; CI matrisi Laravel 12/13 olarak güncellendi.
* `larastan` geliştirme bağımlılığı eklendi (PHPStan iş akışı bağımlılık
  olmadan çalışmıyordu).

## v0.1.0 - 2026-01-18

### What's Changed

* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/1

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/Voxyfy/anadolupay/pull/1

**Full Changelog**: https://github.com/Voxyfy/anadolupay/commits/v0.1.0

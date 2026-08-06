# Changelog

All notable changes to `voxyfy/anadolupay` will be documented in this file.

## v1.0.0 - 2026-08-06

### What's Changed

* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/2
* Bump dependabot/fetch-metadata from 2.5.0 to 3.1.0 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/4

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v0.1.0...v1.0.0

## Unreleased

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

# Changelog

`voxyfy/anadolupay` üzerindeki tüm kayda değer değişiklikler bu dosyada tutulur.

## Yayımlanmamış

### Eklendi — Craftgate

* **Craftgate driver'ı** (`craftgate`). Craftgate bir bankanın sanal POS'u
  değil, birden çok POS'u tek API arkasında toplayan bir orkestrasyon
  platformudur; bu yüzden akış banka driver'larından iki noktada ayrılır:
  müşteri bir banka geçidine form POST edilmez (`3ds-init` hazır bir HTML
  sayfası döner) ve tutarlar kuruş değil ondalık sayı olarak gönderilir.
* Desteklenen işlemler: 3D Secure ve non-secure ödeme, ön provizyon ve
  provizyon kapama, tam ve kısmi iade, durum sorgusu, işlem dökümü, BIN
  sorgusu, taksit seçenekleri ve webhook imza doğrulaması.
* Her üç imza şeması da (API `x-signature`, 3D dönüşü, webhook) Craftgate'in
  resmi istemci depolarındaki **test vektörleriyle** doğrulandı ve testle
  kilitlendi. İmzalanan gövdenin gönderilen gövdeyle aynı olduğu ayrıca
  test ediliyor; ikisinin ayrışması bu API'de en sık yapılan hatadır.
* `TEST-KARTLARI.md` dosyasına Craftgate bölümü eklendi. Craftgate'in kart
  tablosunun tamamı girişli bir portalda olduğu için yalnızca herkese açık
  istemci depolarından doğrulanabilen kartlar listelendi.

### Eklendi

* `BankHttpClient::get()`. Sanal POS'ların çoğu her şeyi POST ile yapar;
  sorgu uçlarını GET olarak sunan sağlayıcılar için gerekliydi.

### Bilinen sınırlar

* Craftgate'te **gün içi iptal ayrı bir uç değildir**; Craftgate henüz
  mutabakata girmemiş işlemi iade isteğinde kendisi void olarak geçer. Bu
  yüzden driver `SupportsCancellation` arayüzünü uygulamaz.
* Craftgate'te **kısmi iade işlem bazındadır**. Bir ödeme birden çok satıcı
  işlemine bölünebildiği için hangi işlemin iade edileceğini paket kendi
  başına seçemez; `metadata['payment_transaction_id']` verilmezse tam iadeye
  sessizce düşmek yerine hata verilir.

## v1.0.1 – v1.0.6 · 2026-08-07

GitHub'ın otomatik ürettiği sürüm notları boş kaldığı için bu altı yamanın
içeriği burada toplu olarak listelenmiştir.

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.0...v1.0.6

### Düzeltildi — Param driver'ı hiç çalışmıyordu

Davranış testleri yazılırken iki hata ortaya çıktı; ikisi de `Xml`
yardımcısındaydı ve Param driver'ını tamamen kullanılamaz kılıyordu:

* `Xml::encode()` `@` önekli anahtarları eleman adı sanıyordu. Param istekleri
  ad alanını `'@xmlns'` ile taşıdığı için **her çağrı `DOMException` ile
  düşüyordu**. Artık `@` önekli anahtarlar öznitelik olarak yazılıyor —
  `decode()`'un öznitelikleri `@ad` ile döndürmesiyle simetrik.
* `Xml::decode()` ad alanlı çocukları atlıyordu. SOAP yanıtlarında gövdenin
  tamamı `soap:` ad alanında olduğu için Param'ın **yanıtı boş görünüyordu**.
  Artık belgede bildirilen her ad alanı dolaşılıyor; anahtar olarak ön eksiz
  yerel ad kullanılıyor. Düz XML kullanan diğer driver'ların davranışı
  değişmedi.

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

### Eklendi

* Türk bankaları için native sanal POS driver'ları: Asseco/Payten NestPay
  (Akbank, İş Bankası, Ziraat, Halkbank, QNB Finansbank, TEB, Şekerbank),
  Garanti BBVA, Yapı Kredi PosNet, Albaraka PosNet V1, VakıfBank/Ziraat PayFlex
  V4, DenizBank InterPos, QNB/Ziraat Katılım PayFor, Kuveyt Türk, Vakıf Katılım.
* Ödeme kuruluşu driver'ları: Akbank POS (yeni JSON API), PayTR, Param, Tosla.
* **Yetenek sözleşmeleri.** `SupportsStatusQuery`, `SupportsCancellation`,
  `SupportsPreAuthorization`, `SupportsOrderHistory`, `SupportsBinQuery`,
  `SupportsInstallmentQuery`, `SupportsRecurringPayments`. Desteklenmeyen
  bir işlem artık `instanceof` ile önceden anlaşılıyor.
* **Sipariş durumu sorgusu** 13 driver'a yayıldı ve `StatusResponse` ile
  normalleştirildi. Bankaların birbirine benzemeyen durum kodları tek
  sözlüğe indirgeniyor; tanınmayan kod `unknown` döner.
* **Eksik iptaller**: Kuveyt Türk (ayrı BOA sorgu servisi) ve Param.
* **Kuveyt Türk iade** — ayrı SOAP servisi üzerinden.
* **Ön provizyon ve provizyon kapama** 12 driver'da; `CapturePaymentData`.
* **İşlem geçmişi** (6 driver), **BIN sorgusu** (Garanti, PayTR, iyzico),
  **taksit seçenekleri** (PayTR, Tosla, iyzico).
* **Tekrarlayan ödeme** (`RecurringPlan`): Asseco, Garanti, PayFlex,
  Akbank POS. Bankanın desteklemediği frekans hata verir.
* **iyzico iadesi** — `/v2/payment/refund` ile tam ve kısmi iade.
* **`Money` value object.** Tutarlar artık paket içinde kuruş cinsinden tam
  sayı olarak taşınır; float aritmetiği ödeme yolundan çıkarıldı.
  `CreatePaymentData` ve `RefundPaymentData` hem `Money` hem `float` kabul
  eder, mevcut çağrılar değişmeden çalışır.
* **Hata sınıflandırması.** `TransportException` (ve alt tipleri
  `GatewayUnreachableException`, `GatewayHttpException`) bankanın kesin
  reddi olan `PaymentFailedException`'dan ayrıldı. Taşıma hataları
  `safeToRetry` bayrağı taşır; zaman aşımı ve HTTP hataları isteğin bankaya
  ulaşmış olabileceği için tekrar denenemez olarak işaretlenir.
* **Yeniden deneme** (`anadolupay.retry`). Yalnızca bankaya ulaşılamayan
  durumlarda çalışır; zaman aşımı ve HTTP hataları çift çekim riski
  taşıdığı için tekrar denenmez. Varsayılan kapalı.
* **Mükerrer ödeme koruması** (`anadolupay.idempotency`). Aynı sipariş
  numarası için kısa bir pencerede ikinci ödeme başlatmayı engeller;
  atomik cache kilidi kullanır. Varsayılan kapalı.
* **Event'ler** (`anadolupay.events`): `PaymentInitiated`, `PaymentVerified`,
  `PaymentFailed`, `RefundIssued`. Kart verisi taşımazlar.
* **PSR-3 loglama** (`anadolupay.logging`). Her banka isteği ve yanıtı, kart
  numarası maskelenip CVV/şifre gizlenerek kaydedilir. Maskeleme hem alan adına
  hem de değerin biçimine (Luhn doğrulamalı) göre çalışır. Varsayılan kapalıdır.
* **Sağlayıcıya özel webhook onay yanıtı** (`ProvidesWebhookAcknowledgement`).
  PayTR bildirimin işlendiğini yalnızca düz metin `OK` yanıtıyla kabul eder;
  paketin webhook rotası artık bunu kendisi döndürüyor. Önceden her zaman
  JSON dönüyordu ve PayTR bildirimi yeniden göndermeye devam ediyordu.
* **`FakeGateway` yetenek arayüzlerini uyguluyor** ve yaptığı işlemleri
  bellekte tutuyor: ödenen sipariş `paid`, iade edilen `refunded` döner.
  Böylece durum sorgusu ve iptal akışları bankaya bağlanmadan test
  edilebiliyor. Başarı oranı `anadolupay.fake.success_rate` ile ayarlanır
  ve artık varsayılan olarak %100'dür — sahte geçidin rastgele
  başarısız olması testleri kırılgan yapıyordu.
* `CardData` DTO'su ve `PaymentResponse` üzerinde 3D Secure form alanları
  (`formAction`, `formFields`, `htmlContent`, `toHtmlForm()`).
* `config/anadolupay.php` içinde banka preset'leri; `AnadoluPay::driver()`
  artık banka anahtarlarını da çözümlüyor.
* Her altyapının imza algoritması için regresyon testleri.
* **Davranış testleri**: VakıfKatılım (sıfır kapsamdaydı), InterPos, PayFor,
  PosNet V1, Param, Tosla, PayFlex. Her driver için 3D form üretimi, dönüş
  imzası, provizyon, iade/iptal, ön provizyon ve durum sorgusu artık
  testle kilitli. Süit 164 → 230 test.

### Değişti

* `createPayment()`, `verify()` ve `refund()` artık `final`; bankaya özel
  davranış `performCreatePayment()`, `performVerify()` ve `performRefund()`
  metotlarında yaşıyor. Böylece idempotency, event yayını ve hata sarmalama
  tek yerde duruyor ve driver override'ları bunları atlayamıyor.
* Laravel 13 desteği; CI matrisi Laravel 12/13 olarak güncellendi.
* **Pest 3 desteği eklendi** (`^3.0|^4.0|^5.0`). Pest 4 en az PHP 8.3, Pest 5
  en az PHP 8.4 istiyor; bu yüzden `composer.json` PHP 8.2 bildirmesine
  rağmen test araç zinciri 8.2'de kurulamıyordu. Kütüphane kodu 8.2
  uyumludur — eksik olan yalnızca test bağımlılığıydı.
* CI matrisine PHP 8.2 eklendi. `composer.json` `^8.2` bildiriyordu ancak
  testler yalnızca 8.3 ve 8.4'te koşuyordu. Laravel 13 en az PHP 8.3
  istediği için o kombinasyon matristen hariç tutuldu.
* `larastan` geliştirme bağımlılığı eklendi (PHPStan iş akışı bağımlılık
  olmadan çalışmıyordu).

### Düzeltildi

* `BankHttpClient` 2xx dışı bir yanıtın düz metin gövdesini query string
  sanıp **başarılı sonuç gibi** döndürebiliyordu; durum kodu artık
  çözümleme denemesinden önce kontrol ediliyor.
* `IyzicoMapper` kart bilgisini yalnızca eski `customer['card']` dizisinden
  okuyordu; birinci sınıf `CardData` alanı yok sayılıyordu.
* `Currency::alphabetic()` her iki dalı aynı olan bir koşul içeriyordu ve
  sıfırla doldurulmuş kodları (Kuveyt Türk `0949`) çözemiyordu.
* `AbstractBankGateway::paymentModelOf()` hiçbir yerde yazılmayan bir
  `anadolupay_payment_model` anahtarını okuyordu; kaldırıldı.

### Belgelendi

* Bazı işlemler sağlayıcı tarafından sunulmuyor; bunlar artık arayüz
  uygulanmayarak açıkça bildiriliyor: PayTR iptal ve ön provizyon sunmaz,
  Akbank POS tekil durum sorgusu sunmaz (yerine işlem geçmişi), Kuveyt
  Türk ön provizyon sunmaz.

## v1.0.0 - 2026-08-06

### What's Changed

* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/2
* Bump dependabot/fetch-metadata from 2.5.0 to 3.1.0 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/4

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v0.1.0...v1.0.0

## v0.1.0 - 2026-01-18

### What's Changed

* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/1

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/Voxyfy/anadolupay/pull/1

**Full Changelog**: https://github.com/Voxyfy/anadolupay/commits/v0.1.0
